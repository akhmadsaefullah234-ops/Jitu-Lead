<?php

namespace App\Welcome;

use App\Actions\SendLibraryItem;
use App\Actions\SendWhatsAppMessage;
use App\Billing\PlanLimits;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Jobs\SendWelcomeMessage;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WelcomeMessage;
use App\Support\PhoneNumber;
use App\WhatsApp\RouteKind;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\WhatsAppException;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The automatic WhatsApp welcome to a buyer who sent a lead form, even when they never open the chat.
 *
 * A form submission books one welcome per lead and form, due after the form's delay. When it comes due it is
 * skipped if the buyer has written to the shop since (the chat or the AI already has them), or, for a lead
 * that was already known, if the shop wrote to the number in the last 24 hours. Outside service hours
 * (21:00 to 05:00 in the agency's time zone) it waits for 05:00. It goes out on the route and number any
 * other message to this lead would use (App\WhatsApp\RouteSelector: the lead owner's gateway number), at most
 * RATE_PER_MINUTE per number. The welcome is itself the introduction, so no number introduction goes before it.
 * A bad number or a refused message is marked failed once and never retried; a gateway that is down is
 * tried again a few times.
 */
class WelcomeSender
{
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 5;

    public const RATE_PER_MINUTE = 20;

    public const RETURNING_QUIET_HOURS = 24;

    public const MAX_ATTEMPTS = 3;

    public const RETRY_MINUTES = 5;

    public function __construct(
        private RouteSelector $routes,
        private SendWhatsAppMessage $send,
        private SendLibraryItem $sendItem,
        private WelcomeText $text,
    ) {}

    /** Books the welcome for a form just sent. Runs inside the tenant. */
    /** @param  array<string, string>  $fieldAnswers  the answers by field key, for {jawaban:key} and {warna} */
    public function schedule(LeadForm $form, ?LandingPage $page, Lead $lead, bool $created, ?string $answers = null, array $fieldAnswers = []): ?WelcomeMessage
    {
        $settings = WelcomeSettings::for($form, $page);

        if (! $settings->active || blank($lead->phone)) {
            return null;
        }

        // Once per lead and form: the same buyer sending the form again gets no second welcome.
        if (WelcomeMessage::query()->where('lead_id', $lead->getKey())->where('lead_form_id', $form->getKey())->exists()) {
            return null;
        }

        try {
            $welcome = WelcomeMessage::query()->create([
                'lead_id' => $lead->getKey(),
                'lead_form_id' => $form->getKey(),
                'landing_page_id' => $page?->getKey(),
                'returning' => ! $created,
                'status' => WelcomeMessage::PENDING,
                'due_at' => now()->addMinutes($settings->delayMinutes),
                'context' => array_filter([
                    'answers' => $answers !== null ? mb_substr($answers, 0, 2000) : null,
                    'fields' => array_map(fn ($v) => mb_substr((string) $v, 0, 500), array_slice($fieldAnswers, 0, 40, true)) ?: null,
                ]),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // the same form sent twice at once
        }

        SendWelcomeMessage::dispatch($welcome->getKey())->delay($welcome->due_at);

        return $welcome;
    }

    /**
     * Works one booked welcome if it is due. Runs inside the tenant.
     *
     * @return string the welcome's status afterwards
     */
    public function process(WelcomeMessage $welcome, Tenant $tenant): string
    {
        // Claimed first, so the queued job and the minute sweep never both send it.
        $claimed = WelcomeMessage::query()->whereKey($welcome->getKey())->where('status', WelcomeMessage::PENDING)
            ->where('due_at', '<=', now())->update(['status' => WelcomeMessage::SENDING, 'updated_at' => now()]);

        if ($claimed === 0) {
            return $welcome->refresh()->status;
        }

        $welcome->refresh();

        try {
            return $this->work($welcome, $tenant);
        } catch (\Throwable $e) {
            report($e);

            return $this->finish($welcome, WelcomeMessage::FAILED, 'gagal: terjadi kesalahan sistem, tidak dicoba lagi');
        }
    }

    private function work(WelcomeMessage $welcome, Tenant $tenant): string
    {
        $lead = Lead::query()->with(['owner', 'property', 'tenant'])->find($welcome->lead_id);
        $form = LeadForm::query()->find($welcome->lead_form_id);

        if ($lead === null || $form === null) {
            return $this->finish($welcome, WelcomeMessage::SKIPPED, null);
        }

        $settings = WelcomeSettings::for($form, $welcome->landing_page_id ? LandingPage::query()->find($welcome->landing_page_id) : null);

        if (! $settings->active) {
            return $this->finish($welcome, WelcomeMessage::SKIPPED, 'dilewati: pesan sambutan formulir ini dimatikan', $lead);
        }

        if (PlanLimits::for($tenant)->readOnly()) {
            return $this->finish($welcome, WelcomeMessage::SKIPPED, 'dilewati: langganan hanya-baca', $lead);
        }

        $conversation = WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]);

        // Already out (a worker that died before writing it down): never a second one.
        $sent = $conversation->messages()->where('origin', 'welcome')->where('is_intro', false)->where('status', '!=', MessageStatus::Failed)
            ->where('created_at', '>=', $welcome->created_at)->first();

        if ($sent !== null) {
            return $this->finish($welcome, WelcomeMessage::SENT, 'terkirim', message: $sent);
        }

        // The buyer already wrote to the shop after sending the form: the chat (or the AI) has them.
        if ($conversation->messages()->where('direction', 'in')->where('created_at', '>=', $welcome->created_at)->exists()) {
            return $this->finish($welcome, WelcomeMessage::SKIPPED, 'dilewati: pembeli sudah chat', $lead);
        }

        if ($welcome->returning && $conversation->messages()->where('direction', 'out')->where('status', '!=', MessageStatus::Failed)
            ->where('sent_at', '>=', now()->subHours(self::RETURNING_QUIET_HOURS))->exists()) {
            return $this->finish($welcome, WelcomeMessage::SKIPPED, 'dilewati: nomor ini sudah dihubungi dalam 24 jam terakhir', $lead);
        }

        if (! self::validNumber($lead->phone)) {
            return $this->finish($welcome, WelcomeMessage::FAILED, 'gagal: nomor '.$lead->phone.' bukan nomor WhatsApp yang valid', $lead);
        }

        $resume = $this->serviceStart($tenant);
        if ($resume !== null) {
            return $this->later($welcome, $resume, 'Pesan sambutan WhatsApp ditunda sampai '.$resume->copy()->setTimezone(self::timezone($tenant))->format('H:i').' (di luar jam layanan)', $lead);
        }

        $decision = $this->routes->decide($conversation);

        if (! $decision->canSend()) {
            return $this->finish($welcome, WelcomeMessage::FAILED, 'gagal: '.$decision->note, $lead);
        }

        if ($decision->kind === RouteKind::PaidTemplate) {
            return $this->finish($welcome, WelcomeMessage::SKIPPED, 'dilewati: hanya nomor resmi yang terhubung, dan pesan pertama di sana harus template berbayar', $lead);
        }

        // A number sending many welcomes at once looks like spam to WhatsApp: the rest wait a minute.
        $recent = WaMessage::query()->where('origin', 'welcome')->where('is_intro', false)->where('channel_id', $decision->channel->getKey())
            ->where('status', '!=', MessageStatus::Failed)->where('sent_at', '>=', now()->subMinute())->count();

        if ($recent >= self::RATE_PER_MINUTE) {
            return $this->later($welcome, now()->addMinute());
        }

        $actor = $lead->owner ?? $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->first();

        if (! $actor instanceof User) {
            return $this->finish($welcome, WelcomeMessage::FAILED, 'gagal: belum ada admin aktif untuk mengirim', $lead);
        }

        $body = $this->text->render($settings->textFor($lead), $lead, $actor, $tenant, $welcome->context['answers'] ?? null, (array) ($welcome->context['fields'] ?? []));

        try {
            $message = ($this->send->from('welcome'))($conversation, $actor, $body);
        } catch (WhatsAppException $e) {
            return $this->finish($welcome, WelcomeMessage::FAILED, 'gagal: '.$e->getMessage(), $lead);
        }

        $welcome->attempts++;

        if ($message->status === MessageStatus::Failed) {
            // A gateway that went down is tried again a few times; a refused number never is.
            // SendWhatsAppMessage already noted the failure on the lead.
            if ($message->channel !== null && ! $message->channel->refresh()->isConnected() && $welcome->attempts < self::MAX_ATTEMPTS) {
                return $this->later($welcome, now()->addMinutes(self::RETRY_MINUTES), 'Pesan sambutan WhatsApp dicoba lagi '.self::RETRY_MINUTES.' menit lagi, nomor pengirim sedang terputus', $lead);
            }

            return $this->finish($welcome, WelcomeMessage::FAILED, mb_substr('gagal: '.($message->error ?: 'pesan ditolak'), 0, 250), message: $message);
        }

        $note = $this->attach($settings->mediaAssetId, $conversation, $actor);

        return $this->finish($welcome, WelcomeMessage::SENT, 'terkirim'.$note, message: $message);
    }

    /** The product photo after the text. A problem with it never undoes the welcome already sent. */
    private function attach(?int $assetId, WaConversation $conversation, User $actor): string
    {
        $asset = $assetId ? MediaAsset::query()->where('active', true)->find($assetId) : null;

        if ($asset === null) {
            return '';
        }

        try {
            $sent = ($this->sendItem->from('welcome'))($conversation->refresh(), $actor, $asset);
        } catch (WhatsAppException $e) {
            return ' (lampiran gagal: '.$e->getMessage().')';
        }

        return $sent->status === MessageStatus::Failed ? ' (lampiran gagal: '.$sent->error.')' : ' + '.$asset->title;
    }

    /**
     * @param  string|null  $note  "dilewati: ..." or "gagal: ...", also written on the lead (a sent welcome is
     *                             already noted by SendWhatsAppMessage)
     */
    private function finish(WelcomeMessage $welcome, string $status, ?string $note, ?Lead $lead = null, ?WaMessage $message = null): string
    {
        $welcome->forceFill([
            'status' => $status,
            'note' => $note !== null ? mb_substr($note, 0, 250) : null,
            'wa_message_id' => $message?->getKey(),
        ])->save();

        if ($lead !== null && $note !== null) {
            $lead->activities()->create([
                'type' => 'welcome',
                'body' => 'Pesan sambutan WhatsApp '.$note,
                'meta' => ['welcome_id' => $welcome->getKey(), 'status' => $status],
            ]);
        }

        return $status;
    }

    private function later(WelcomeMessage $welcome, CarbonInterface $at, ?string $activity = null, ?Lead $lead = null): string
    {
        $welcome->forceFill(['status' => WelcomeMessage::PENDING, 'due_at' => $at])->save();

        if ($activity !== null && $lead !== null) {
            $lead->activities()->create(['type' => 'welcome', 'body' => $activity, 'meta' => ['welcome_id' => $welcome->getKey(), 'status' => 'pending']]);
        }

        SendWelcomeMessage::dispatch($welcome->getKey())->delay($at);

        return WelcomeMessage::PENDING;
    }

    /** When service hours start again, or null while they are on (05:00 to 21:00 local time). */
    public function serviceStart(Tenant $tenant): ?Carbon
    {
        $now = now();
        $local = $now->copy()->setTimezone(self::timezone($tenant));

        $start = match (true) {
            $local->hour >= self::QUIET_FROM => $local->copy()->addDay()->setTime(self::QUIET_UNTIL, 0),
            $local->hour < self::QUIET_UNTIL => $local->copy()->setTime(self::QUIET_UNTIL, 0),
            default => null,
        };

        // Back in the clock's own zone: dates are stored as its wall time.
        return $start?->setTimezone($now->getTimezone());
    }

    private static function timezone(Tenant $tenant): string
    {
        return $tenant->timezone ?: config('app.timezone');
    }

    /** An Indonesian mobile number, or a foreign one of a plausible length. */
    public static function validNumber(?string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (str_starts_with($digits, '62')) {
            return PhoneNumber::whatsapp($phone) !== null;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15;
    }
}
