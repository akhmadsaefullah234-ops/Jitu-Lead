<?php

namespace App\Tracking;

use App\Automation\RetailStages;
use App\Enums\Interest;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Jobs\SendConversionEvents;
use App\Models\ConversionEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conversion events from what happens to a lead in the CRM, not only from web forms: entering a stage the agency
 * mapped to an event (moved by a person, by StageAutomation or by the AI), or becoming Hot. Each lead sends each
 * event once (event_id "lead-{id}-{event}"); a Purchase may be sent again for a new order. Nothing is sent until
 * the agency switches it on under Pelacakan iklan > Event dari pipeline. Sending is queued
 * (App\Jobs\SendConversionEvents) and never stops the stage move.
 */
class PipelineConversions
{
    /** The trigger of an event a web form sent from the browser and the server. */
    public const FORM = 'form';

    /** The Meta events a stage or "Hot" can send. */
    public const EVENTS = [
        'Lead' => 'Lead (calon pelanggan)',
        'QualifiedLead' => 'QualifiedLead (calon pelanggan berkualitas)',
        'InitiateCheckout' => 'InitiateCheckout (mulai pemesanan)',
        'AddToCart' => 'AddToCart (menambah ke keranjang)',
        'Purchase' => 'Purchase (pembelian)',
        'Schedule' => 'Schedule (jadwal janji / survei)',
        'CompleteRegistration' => 'CompleteRegistration (pendaftaran selesai)',
        'Contact' => 'Contact (menghubungi)',
    ];

    /** The same event in TikTok's names; null: TikTok has none like it. */
    public const TIKTOK = [
        'Lead' => 'Lead', 'QualifiedLead' => null, 'InitiateCheckout' => 'InitiateCheckout', 'AddToCart' => 'AddToCart',
        'Purchase' => 'Purchase', 'Schedule' => 'Schedule', 'CompleteRegistration' => 'CompleteRegistration', 'Contact' => 'Contact',
    ];

    /** The same event in Conversions API for Business Messaging; null: not one Meta accepts there. */
    public const MESSAGING = [
        'Lead' => 'LeadSubmitted', 'QualifiedLead' => 'QualifiedLead', 'InitiateCheckout' => 'InitiateCheckout', 'AddToCart' => 'AddToCart',
        'Purchase' => 'Purchase', 'Schedule' => null, 'CompleteRegistration' => null, 'Contact' => null,
    ];

    /** Called after every lead save: reports a stage it entered or becoming Hot, once the save is committed. */
    public static function saved(Lead $lead): void
    {
        $created = $lead->wasRecentlyCreated;
        $stageId = ($created ? $lead->stage_id !== null : $lead->wasChanged('stage_id')) ? $lead->stage_id : null;
        $hot = $lead->interest === Interest::Hot && ($created || $lead->wasChanged('interest'));

        if ($stageId === null && ! $hot) {
            return;
        }

        $at = ($stageId !== null ? $lead->stage_entered_at : null)?->getTimestamp() ?? time();
        $leadId = $lead->getKey();
        $tenantId = (int) $lead->tenant_id;

        DB::afterCommit(function () use ($leadId, $tenantId, $stageId, $hot, $created, $at) {
            try {
                $current = app(CurrentTenant::class);
                $run = fn () => app(self::class)->report($leadId, $stageId, $hot, $created, $at);
                $current->id() === $tenantId ? $run() : $current->run(Tenant::query()->find($tenantId), $run);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Must run inside the lead's tenant. */
    public function report(int $leadId, ?int $stageId, bool $hot, bool $created, int $at): void
    {
        $settings = TrackingSetting::active();
        $pipeline = $settings?->pipeline();

        if ($pipeline === null || ! $pipeline['enabled'] || ! $this->canSend($settings, $pipeline)) {
            return;
        }

        $lead = Lead::query()->with(['property', 'source', 'conversation.lastInboundChannel'])->find($leadId);

        // A web form already reported its new lead from the browser and the server; do not count it again.
        if ($lead === null || ($created && $lead->source?->type === 'form')) {
            return;
        }

        $events = [];

        if ($hot && $pipeline['hot'] !== null) {
            $events[] = [$pipeline['hot'], 'hot'];
        }

        if ($stageId !== null && ($event = $pipeline['stages'][$stageId] ?? null) !== null) {
            $events[] = [$event, 'stage'];
        }

        foreach ($events as [$event, $trigger]) {
            $this->send($lead, $event, $trigger, $at, $pipeline);
        }
    }

    /**
     * Queues one event for the lead unless it already sent it: by any route (a web form's event counts too), so
     * one person is never counted twice for the same event. A Purchase is the exception: once per order.
     */
    public function send(Lead $lead, string $event, string $trigger, int $at, array $pipeline): ?ConversionEvent
    {
        if ($event !== 'Purchase' && $this->alreadySent($lead, $event)) {
            return null;
        }

        $eventId = self::eventId($lead, $event);

        $record = ConversionEvent::query()->createOrFirst(['event_id' => $eventId], ['lead_id' => $lead->getKey(), 'event_name' => $event, 'trigger' => $trigger]);

        if (! $record->wasRecentlyCreated) {
            return null;
        }

        SendConversionEvents::dispatch((int) $lead->tenant_id, $this->payload($lead, $event, $eventId, $at, $trigger, $pipeline, $record->getKey())->toArray());

        return $record;
    }

    /**
     * Whether the lead already sent this event: a pipeline or form event on record, or, for a form lead from
     * before forms were recorded, the form's event (Lead, or the agency's form event) which its browser sent.
     */
    private function alreadySent(Lead $lead, string $event): bool
    {
        $sent = ConversionEvent::query()->where('lead_id', $lead->getKey());

        if ((clone $sent)->where('event_name', $event)->exists()) {
            return true;
        }

        if ($lead->source?->type !== 'form' || (clone $sent)->where('trigger', self::FORM)->exists()) {
            return false;
        }

        return in_array($event, array_unique(['Lead', TrackingSetting::active()?->eventFor('meta') ?? 'Lead']), true);
    }

    /**
     * Notes the event a web form sent for its new lead (with the browser's event_id), so the pipeline does not
     * send the same event for that person again.
     */
    public static function recordFormEvent(Lead $lead, string $eventId, ?string $event): void
    {
        if ($event === null || $event === '') {
            return;
        }

        try {
            ConversionEvent::query()->createOrFirst(['event_id' => mb_substr($eventId, 0, 120)], ['lead_id' => $lead->getKey(), 'event_name' => mb_substr($event, 0, 40), 'trigger' => self::FORM]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** "lead-12-Lead"; a Purchase carries its order ("lead-12-Purchase-345") so a new order counts again. */
    public static function eventId(Lead $lead, string $event): string
    {
        $order = $event === 'Purchase'
            ? LeadActivity::query()->where('lead_id', $lead->getKey())->where('type', 'order_recorded')->max('id')
            : null;

        return 'lead-'.$lead->getKey().'-'.$event.($order ? '-'.$order : '');
    }

    public function payload(Lead $lead, string $event, string $eventId, int $at, string $trigger, array $pipeline, ?int $recordId = null): ConversionPayload
    {
        $tracking = (array) (($lead->custom_fields ?? [])['pelacakan'] ?? []);

        return new ConversionPayload(
            eventId: $eventId,
            eventTime: $at,
            sourceUrl: null,
            emailHash: filled($lead->email) ? self::hash(mb_strtolower(trim($lead->email))) : null,
            phoneHash: self::phoneHash($lead->phone),
            ip: null,
            userAgent: null,
            ids: array_filter(array_intersect_key($tracking, array_flip(['fbc', 'fbp', 'ttclid', 'ttp'])), fn ($v) => is_string($v) && $v !== ''),
            event: ['meta' => $event, 'tiktok' => self::TIKTOK[$event] ?? null],
            extra: [
                'action_source' => 'system_generated',
                'lead_id' => $lead->getKey(),
                'record' => $recordId,
                'trigger' => $trigger,
                'user' => self::userData($lead),
                'custom' => self::customData($lead),
                'messaging' => $this->messaging($lead, $event, $trigger, $pipeline),
            ],
        );
    }

    /**
     * The second route for chats from a Click-to-WhatsApp ad on the official API: Conversions API for Business
     * Messaging, which Meta ties to the ad click itself (ctwa_clid). Only when the agency turned it on, the chat
     * came through a Cloud API number with its WhatsApp Business Account id, and the lead has a ctwa_clid.
     *
     * @return array{channel_id: int, event: string, ctwa_clid: string, waba_id: string}|null
     */
    private function messaging(Lead $lead, string $event, string $trigger, array $pipeline): ?array
    {
        $name = $event === 'Lead' && $trigger === 'hot' ? 'QualifiedLead' : (self::MESSAGING[$event] ?? null);
        $clid = self::ctwaClid($lead);
        $channel = $lead->conversation?->lastInboundChannel;

        if (! $pipeline['messaging'] || $name === null || $clid === null || ! $channel instanceof WaChannel || ! $channel->isOfficial() || blank($channel->credential('waba_id'))) {
            return null;
        }

        return ['channel_id' => $channel->getKey(), 'event' => $name, 'ctwa_clid' => $clid, 'waba_id' => (string) $channel->credential('waba_id')];
    }

    /** @return array<string, string> Meta's extra user_data keys, each hashed the way Meta asks */
    public static function userData(Lead $lead): array
    {
        $parts = (array) ($lead->shipping_address_parts ?? []);
        $name = self::letters((string) $lead->name, true);
        $words = $name === '' || $name === 'tanpa nama' ? [] : explode(' ', $name);
        $city = preg_replace('/^(kota administrasi|kota|kabupaten|kab\.?)\s+/u', '', mb_strtolower(trim((string) ($parts['kota'] ?? ''))));
        $zip = preg_replace('/\D+/', '', (string) ($parts['kode_pos'] ?? ''));

        return array_filter([
            'fn' => isset($words[0]) ? self::hash($words[0]) : null,
            'ln' => count($words) > 1 ? self::hash(implode('', array_slice($words, 1))) : null,
            'ct' => self::hash(self::letters((string) $city)),
            'st' => self::hash(self::letters((string) ($parts['provinsi'] ?? $lead->shipping_province ?? ''))),
            'zp' => strlen($zip) === 5 ? self::hash($zip) : null,
            'country' => self::hash('id'),
            'external_id' => self::hash((string) $lead->getKey()),
        ]);
    }

    /**
     * What the conversion is worth: the order's products times their quantities (no shipping), else the price of
     * the lead's product. The ad click ids ride along as a note only.
     *
     * @return array<string, mixed>
     */
    public static function customData(Lead $lead): array
    {
        $items = array_values(array_filter((array) ($lead->order_items ?? []), 'is_array'));

        if ($items !== []) {
            $priced = array_filter($items, fn ($i) => isset($i['price']));
            $value = $priced === [] ? null : array_sum(array_map(fn ($i) => (int) $i['price'] * max(1, (int) ($i['quantity'] ?? 1)), $priced));
            $ids = array_values(array_unique(array_map('strval', array_filter(array_column($items, 'product_id')))));
            $name = implode(', ', array_unique(array_filter(array_column($items, 'name'))));
            $count = array_sum(array_map(fn ($i) => max(1, (int) ($i['quantity'] ?? 1)), $items));
        } else {
            $product = $lead->property;
            $value = $product?->price_from !== null ? (int) $product->price_from * max(1, (int) ($lead->quantity ?? 1)) : null;
            $ids = $product ? [(string) $product->getKey()] : [];
            $name = $product?->name;
            $count = $product ? max(1, (int) ($lead->quantity ?? 1)) : null;
        }

        return array_filter([
            'currency' => PixelEvents::CURRENCY,
            'value' => $value,
            'content_ids' => $ids ?: null,
            'content_type' => $ids ? 'product' : null,
            'content_name' => filled($name) ? mb_substr($name, 0, 200) : null,
            'num_items' => $count,
            'ctwa_clid' => self::ctwaClid($lead),
            'ad_id' => filled($lead->ad_id) ? (string) $lead->ad_id : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Keeps the ad click cookies a form sent (fbc, fbp, ttclid, ttp) on a new lead, so its later CRM events can
     * be matched to the ad too.
     *
     * @param  array<string, mixed>  $data
     */
    public static function rememberBrowserIds(Lead $lead, array $data): void
    {
        $ids = array_filter(array_intersect_key($data, array_flip(['fbc', 'fbp', 'ttclid', 'ttp'])), fn ($v) => is_string($v) && $v !== '' && mb_strlen($v) <= 200);

        if ($ids !== []) {
            $lead->forceFill(['custom_fields' => ['pelacakan' => $ids] + (array) $lead->custom_fields])->save();
        }
    }

    public static function phoneHash(?string $phone): ?string
    {
        $digits = ltrim((string) PhoneNumber::normalize($phone), '+');

        return strlen($digits) >= 8 ? self::hash($digits) : null;
    }

    public static function ctwaClid(Lead $lead): ?string
    {
        $clid = (($lead->custom_fields ?? [])['iklan'] ?? [])['ctwa_clid'] ?? null;

        return is_string($clid) && $clid !== '' ? $clid : null;
    }

    /**
     * The default mapping for an agency's stages; switched off until the agency turns it on.
     * Shops: Hot = Lead, "Order masuk" = InitiateCheckout, "Dikemas & dikirim" = Purchase.
     * Property: the survey stage = Schedule, akad/closing = Purchase.
     *
     * @return array{enabled: bool, hot: ?string, stages: array<int, string>, messaging: bool}
     */
    public static function defaults(?string $industry): array
    {
        $stages = Stage::query()->orderBy('position')->orderBy('id')->get();
        $map = [];

        if ($industry === 'retail') {
            $retail = new RetailStages;
            foreach ([RetailStages::ORDER => 'InitiateCheckout', RetailStages::SHIPPING => 'Purchase'] as $key => $event) {
                if ($stage = $retail->stage($key)) {
                    $map[$stage->getKey()] = $event;
                }
            }
        } else {
            $survey = $stages->first(fn (Stage $s) => $s->requirement === StageRequirement::Survey || str_contains(mb_strtolower($s->name), 'survei'));
            $won = $stages->first(fn (Stage $s) => preg_match('/akad|closing/i', $s->name)) ?? $stages->first(fn (Stage $s) => $s->type === StageType::Won);

            if ($survey) {
                $map[$survey->getKey()] = 'Schedule';
            }
            if ($won) {
                $map[$won->getKey()] = 'Purchase';
            }
        }

        return ['enabled' => false, 'hot' => $industry === 'retail' ? 'Lead' : null, 'stages' => $map, 'messaging' => false];
    }

    /** Whether any route could send: a Meta or TikTok pixel with its token, or the business messaging beta. */
    private function canSend(TrackingSetting $settings, array $pipeline): bool
    {
        return (filled($settings->meta_pixel_id) && filled($settings->credential('meta_capi_token')))
            || (filled($settings->tiktok_pixel_id) && filled($settings->credential('tiktok_events_token')))
            || $pipeline['messaging'];
    }

    private static function hash(string $value): ?string
    {
        return $value === '' ? null : hash('sha256', $value);
    }

    /** Lower case letters only (and single spaces when asked), as Meta wants names, cities and provinces. */
    private static function letters(string $text, bool $spaces = false): string
    {
        $text = mb_strtolower(trim($text));

        return $spaces
            ? trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\s]+/u', '', $text)))
            : preg_replace('/[^\p{L}]+/u', '', $text);
    }
}
