<?php

namespace App\WhatsApp;

use App\Automation\RetailStages;
use App\Enums\Interest;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Enums\WaChannelType;
use App\Jobs\ApplyWhatsAppLabels;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaLabelEvent;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trial: puts WhatsApp Business labels on a lead's chat from the pipeline, through the QR gateway, so the
 * Click-to-WhatsApp signals Meta reads from labels ("Prospek" = lead, "Order baru" = order, "Lunas" = paid) go out
 * without an admin labelling chats on the phone. Off until a gateway number turns it on. Each label goes on once
 * per lead, in order (a lead that jumps to "Lunas" gets "Prospek" and "Order baru" first), never taken off. Runs on
 * the same moments as App\Tracking\PipelineConversions (stage moves by people, StageAutomation, the AI; Hot),
 * after commit, queued, and never stops the stage move. Whether Meta counts labels put on from a linked device the
 * same as from the phone is not known yet: every attempt is recorded so it can be checked in Ads Manager.
 */
class BusinessLabels
{
    public const RULES = 3;

    /** How long a label the account does not have yet is waited for before it counts as failed. */
    public const WAIT_DAYS = 7;

    /** Names that mean the same default label (as the gateway matches them: gateway/src/labels.js). */
    private const SAME = [
        ['prospek', 'lead', 'calon pelanggan'],
        ['order baru', 'new order', 'pesanan baru'],
        ['lunas', 'paid', 'dibayar', 'sudah dibayar'],
    ];

    /** Activity text per outcome. */
    private const NOTES = [
        'sent' => "Label WhatsApp ':label' dipasang (uji coba)",
        'label_not_found' => "Label WhatsApp ':label' gagal dipasang (uji coba): label itu belum ada di WhatsApp Business. Pasang label itu sekali secara manual di HP agar tersinkron.",
        'not_business' => "Label WhatsApp ':label' gagal dipasang (uji coba): nomor ini bukan akun WhatsApp Business.",
        'error' => "Label WhatsApp ':label' gagal dipasang (uji coba): gateway tidak bisa dihubungi.",
        'not_from_ad' => "Label WhatsApp ':label' dilewati (uji coba): chat bukan dari iklan.",
        'waiting_label' => "Label WhatsApp ':label' menunggu (uji coba): label itu belum ada di WhatsApp Business. Pasang label itu sekali di HP, lalu klik \"Muat label dari WhatsApp\" di Nomor WhatsApp. Dicoba lagi otomatis selama 7 hari.",
        'expired' => "Label WhatsApp ':label' gagal dipasang (uji coba): setelah 7 hari menunggu, label itu tetap belum ada di WhatsApp Business.",
    ];

    /** Called after every lead save: a stage it entered or becoming Hot, once the save is committed. */
    public static function saved(Lead $lead): void
    {
        $created = $lead->wasRecentlyCreated;
        $stageId = ($created ? $lead->stage_id !== null : $lead->wasChanged('stage_id')) ? $lead->stage_id : null;
        $hot = $lead->interest === Interest::Hot && ($created || $lead->wasChanged('interest'));

        if ($stageId === null && ! $hot) {
            return;
        }

        $leadId = $lead->getKey();
        $tenantId = (int) $lead->tenant_id;

        DB::afterCommit(function () use ($leadId, $tenantId, $stageId, $hot) {
            try {
                $current = app(CurrentTenant::class);
                $run = fn () => app(self::class)->report($leadId, $stageId, $hot);
                $current->id() === $tenantId ? $run() : $current->run(Tenant::query()->find($tenantId), $run);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Must run inside the lead's tenant. */
    public function report(int $leadId, ?int $stageId, bool $hot): void
    {
        $lead = Lead::query()->with('conversation.lastInboundChannel')->find($leadId);
        $channel = $lead?->conversation?->lastInboundChannel;

        // Only chats on a QR gateway number with the trial on; the Cloud API gets its own route later.
        if (! $channel instanceof WaChannel || $channel->type !== WaChannelType::Gateway || blank($lead->phone)) {
            return;
        }

        $settings = self::settings($channel);

        if (! $settings['enabled']) {
            return;
        }

        $labels = self::labelsFor($settings, $stageId, $hot, $lead->payment_type);

        if ($labels === []) {
            return;
        }

        $fromAd = self::fromAd($lead);
        $queued = [];

        foreach ($labels as $label) {
            $event = WaLabelEvent::query()->createOrFirst(['lead_id' => $lead->getKey(), 'label' => $label], [
                'wa_channel_id' => $channel->getKey(),
                'status' => $settings['ads_only'] && ! $fromAd ? 'skipped' : 'pending',
                'error' => $settings['ads_only'] && ! $fromAd ? 'not_from_ad' : null,
            ]);

            if (! $event->wasRecentlyCreated) {
                continue;
            }

            if ($event->status === 'skipped') {
                self::note($lead, $label, 'not_from_ad', $channel);
            } else {
                $queued[] = $event->getKey();
            }
        }

        if ($queued !== []) {
            ApplyWhatsAppLabels::dispatch((int) $lead->tenant_id, $queued);
        }
    }

    /**
     * The labels a moment puts on, in order: the last rule it fires and every rule before it (so a jump to the
     * last step labels the earlier steps first).
     *
     * @return list<string>
     */
    public static function labelsFor(array $settings, ?int $stageId, bool $hot, ?string $payment): array
    {
        $last = null;

        foreach ($settings['rules'] as $i => $rule) {
            $stages = $payment === 'cod' && $rule['cod_stages'] !== [] ? $rule['cod_stages'] : $rule['stages'];

            if (($hot && $rule['hot']) || ($stageId !== null && in_array($stageId, $stages, true))) {
                $last = $i;
            }
        }

        if ($last === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($rule) => $rule['name'], array_slice($settings['rules'], 0, $last + 1)), fn ($n) => $n !== '')));
    }

    /** A chat that began with a Click-to-WhatsApp ad: the lead has its ad id, its ctwa_clid or its ad data. */
    public static function fromAd(Lead $lead): bool
    {
        $ad = (array) (($lead->custom_fields ?? [])['iklan'] ?? []);

        return filled($lead->ad_id) || filled($ad['ctwa_clid'] ?? null) || $ad !== [];
    }

    /**
     * The channel's settings, with the industry's defaults where none are saved.
     *
     * @return array{enabled: bool, ads_only: bool, rules: list<array{name: string, hot: bool, stages: list<int>, cod_stages: list<int>}>, known: list<string>}
     */
    public static function settings(WaChannel $channel): array
    {
        $stored = (array) ($channel->label_settings ?? []);
        $defaults = self::defaults(Industry::of($channel->tenant)->key);

        if (! isset($stored['rules'])) {
            $stored['rules'] = $defaults['rules'];
        }

        $ids = fn ($list) => array_values(array_unique(array_map('intval', array_filter((array) $list, fn ($v) => is_numeric($v) && (int) $v > 0))));
        $rules = [];

        for ($i = 0; $i < self::RULES; $i++) {
            $rule = (array) ($stored['rules'][$i] ?? []);
            $rules[] = [
                'name' => mb_substr(trim((string) ($rule['name'] ?? $defaults['rules'][$i]['name'])), 0, 60),
                'hot' => (bool) ($rule['hot'] ?? false),
                'stages' => $ids($rule['stages'] ?? []),
                'cod_stages' => $ids($rule['cod_stages'] ?? []),
            ];
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'ads_only' => (bool) ($stored['ads_only'] ?? true),
            'rules' => $rules,
            'known' => array_values(array_filter((array) ($stored['known'] ?? []), 'is_string')),
        ];
    }

    /**
     * Off, only chats from ads, and per industry:
     * shops: Hot = Prospek, "Order masuk" = Order baru, "Dikemas & dikirim" (transfer, or payment unknown) or
     * "Selesai" (cash on delivery) = Lunas; property: Hot or the survey stage = Prospek, the booking stage = Order
     * baru, the won stage (akad/closing) = Lunas. Must run inside the tenant.
     *
     * @return array{enabled: bool, ads_only: bool, rules: list<array{name: string, hot: bool, stages: list<int>, cod_stages: list<int>}>}
     */
    public static function defaults(?string $industry): array
    {
        $rules = [
            ['name' => 'Prospek', 'hot' => true, 'stages' => [], 'cod_stages' => []],
            ['name' => 'Order baru', 'hot' => false, 'stages' => [], 'cod_stages' => []],
            ['name' => 'Lunas', 'hot' => false, 'stages' => [], 'cod_stages' => []],
        ];

        if ($industry === 'retail') {
            $retail = new RetailStages;
            $id = fn (string $key) => $retail->stage($key)?->getKey();
            $rules[1]['stages'] = array_values(array_filter([$id(RetailStages::ORDER)]));
            $rules[2]['stages'] = array_values(array_filter([$id(RetailStages::SHIPPING), $id(RetailStages::DONE)]));
            $rules[2]['cod_stages'] = array_values(array_filter([$id(RetailStages::DONE)]));
        } else {
            $stages = Stage::query()->orderBy('position')->orderBy('id')->get();
            $survey = $stages->first(fn (Stage $s) => $s->requirement === StageRequirement::Survey || str_contains(mb_strtolower($s->name), 'survei'));
            $booking = $stages->first(fn (Stage $s) => $s->type === StageType::Open && ($s->requirement === StageRequirement::Booking || preg_match('/booking|utj/i', $s->name)));
            $rules[0]['stages'] = array_values(array_filter([$survey?->getKey()]));
            $rules[1]['stages'] = array_values(array_filter([$booking?->getKey()]));
            $rules[2]['stages'] = $stages->filter(fn (Stage $s) => $s->type === StageType::Won)->pluck('id')->values()->all();
        }

        return ['enabled' => false, 'ads_only' => true, 'rules' => $rules];
    }

    /** Writes the outcome on the lead: no phone number, no token. */
    public static function note(Lead $lead, string $label, string $outcome, ?WaChannel $channel = null): void
    {
        $lead->activities()->create([
            'type' => 'note',
            'body' => str_replace(':label', $label, self::NOTES[$outcome] ?? self::NOTES['error']),
            'meta' => ['wa_label' => $label, 'outcome' => $outcome, 'wa_channel_id' => $channel?->getKey()],
        ]);
    }

    /**
     * Per label, how many chats in the last 30 days got it, failed, or were skipped.
     *
     * @return array<string, array{sent: int, failed: int, skipped: int, pending: int, waiting_label: int}>
     */
    public static function summary(WaChannel $channel, int $days = 30): array
    {
        $out = [];

        $rows = WaLabelEvent::query()->where('wa_channel_id', $channel->getKey())->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('label, status, count(*) as n')->groupBy('label', 'status')->get();

        foreach ($rows as $row) {
            $out[$row->label] ??= ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0, 'waiting_label' => 0];
            $out[$row->label][$row->status] = (int) $row->n;
        }

        return $out;
    }

    /**
     * The account label that `$wanted` names among `$names`: the exact name first, then its other spellings
     * ("Prospek" = "Lead"), the same way the gateway picks it. Null when the account has none.
     *
     * @param  list<string>  $names
     */
    public static function match(array $names, string $wanted): ?string
    {
        $normalize = fn (string $n) => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(\Normalizer::normalize($n, \Normalizer::FORM_KC) ?: $n)));
        $w = $normalize($wanted);
        $group = collect(self::SAME)->first(fn (array $g) => in_array($w, $g, true)) ?? [];
        $byName = [];

        foreach ($names as $name) {
            $byName[$normalize($name)] ??= $name;
        }

        foreach (array_values(array_unique([$w, ...$group])) as $candidate) {
            if (isset($byName[$candidate])) {
                return $byName[$candidate];
            }
        }

        return null;
    }

    /**
     * Per rule label (e.g. Prospek, Order baru, Lunas): the account label it goes on as, by the labels last loaded
     * from WhatsApp, or null when the account has none.
     *
     * @return array<string, ?string>
     */
    public static function checklist(WaChannel $channel): array
    {
        $settings = self::settings($channel);
        $out = [];

        foreach ($settings['rules'] as $rule) {
            if ($rule['name'] !== '') {
                $out[$rule['name']] = self::match($settings['known'], $rule['name']);
            }
        }

        return $out;
    }

    /**
     * Labels waiting for the account to have them: the ones it now has go on (queued, in order), the ones waited
     * for 7 days fail. `$names`: the account's labels, asked from the gateway when not given. Must run inside the
     * channel's tenant.
     *
     * @param  list<string>|null  $names
     * @return array{retried: int, expired: int}
     */
    public static function retryWaiting(WaChannel $channel, ?array $names = null): array
    {
        $events = WaLabelEvent::query()->with('lead')->where('wa_channel_id', $channel->getKey())->where('status', 'waiting_label')
            ->orderBy('lead_id')->orderBy('id')->get();

        if ($events->isEmpty()) {
            return ['retried' => 0, 'expired' => 0];
        }

        $enabled = self::settings($channel)['enabled'];
        $names ??= $enabled ? array_column((new GatewayProvider($channel))->labels()['labels'], 'name') : [];
        $retry = [];
        $expired = 0;

        foreach ($events as $event) {
            if ($event->waiting_since !== null && $event->waiting_since->lte(now()->subDays(self::WAIT_DAYS))) {
                $event->forceFill(['status' => 'failed', 'error' => 'label_not_found'])->save();
                $expired++;

                if ($event->lead !== null) {
                    self::note($event->lead, $event->label, 'expired', $channel);
                }

                continue;
            }

            if ($enabled && self::match($names, $event->label) !== null) {
                $event->forceFill(['status' => 'pending'])->save();
                $retry[] = $event->getKey();
            }
        }

        if ($retry !== []) {
            ApplyWhatsAppLabels::dispatch((int) $channel->tenant_id, $retry);
        }

        return ['retried' => count($retry), 'expired' => $expired];
    }
}
