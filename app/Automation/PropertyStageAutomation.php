<?php

namespace App\Automation;

use App\Actions\MoveLeadToStage;
use App\Actions\SendWhatsAppMessage;
use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Enums\StageType;
use App\Models\FollowUpLog;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\RouteSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Moves a property agency's leads through its pipeline by themselves and keeps the agents' tasks to what needs a
 * person (Pengaturan > Otomatisasi tahap, PropertyStageAutomationSettings). The property counterpart of the shops'
 * StageAutomation, which it never touches: every method does nothing outside a property agency.
 *
 * - A client answering our first message moves Lead baru to Dihubungi; a client who never answers stays put.
 * - A purpose plus a domicile, budget or way of paying, with at least two answers from the client, moves the lead
 *   to Terkualifikasi.
 * - A survey the client cancels in the chat takes the lead back to Terkualifikasi with a task to offer a new date.
 * - A few hours after the survey time the lead moves to Sudah survei, optionally with a thank-you message.
 * - A client silent after the follow-ups ends in Gugur ("Tidak ada respons"); one who writes again comes back.
 * - A client ready to book is handed to an agent with a task; Booking and Closing stay with people.
 *
 * Every move goes through MoveLeadToStage, so it is written to the activity ("Pindah otomatis ...: reason"), the new
 * stage's follow-ups run, and its conversion events and WhatsApp labels follow. Leads only move ahead, never out
 * of a won stage, and an agent's own move always stands. Must run inside the tenant.
 */
class PropertyStageAutomation
{
    public const BOOKING_REASON = 'Klien menyatakan siap booking';

    public const BOOKING_TASK = 'Proses booking: hubungi klien soal unit, tanda jadi, dan berkas';

    public const HANDOFF_TASK = 'Tanggapi chat yang diserahkan AI: ';

    public const CANCELLED_TASK = 'Klien membatalkan survei: tawarkan jadwal baru';

    /** Messages that are not an answer to the client: they never close the "Hubungi" task. */
    public const NOT_A_REPLY = ['welcome', 'shipping', 'follow_up', 'ai_quiet', self::ORIGIN];

    /** Marks the thank-you message after a survey. */
    public const ORIGIN = 'stage_auto';

    /** The client says they will book now: "mau booking", "siap bayar tanda jadi", "jadi ambil unitnya". */
    private const BOOKING = '/\b(?:mau|siap|ingin|pengen|pingin|jadi|langsung|oke|ok)\s+(?:(?:aku|saya)\s+)?(?:booking|bayar\s+booking|bayar\s+tanda\s+jadi|tanda\s+jadi|bayar\s+utj|utj|ambil\s+unit(?:nya)?|ambil\s+yang|transfer\s+booking|dp)\b/iu';

    private ?PropertyStages $stages = null;

    public function __construct(private CurrentTenant $current, private MoveLeadToStage $move, private RouteSelector $routes) {}

    public function applies(): bool
    {
        return PropertyStageAutomationSettings::appliesTo($this->current->get());
    }

    public function on(string $rule): bool
    {
        return $this->applies() && PropertyStageAutomationSettings::of($this->current->get())->enabled($rule);
    }

    public function stages(): PropertyStages
    {
        return $this->stages ??= new PropertyStages;
    }

    public static function wantsBooking(string $text): bool
    {
        return preg_match(self::BOOKING, $text) === 1;
    }

    // ---- Messages ---------------------------------------------------------------------------------------------

    /** A message from the client (not the first message of a new lead). */
    public function inbound(Lead $lead, WaMessage $message): void
    {
        if (! $this->applies()) {
            return;
        }

        $this->stages = null;
        $lead->loadMissing('stage');

        if ($this->on('reopen') && $lead->stage?->type === StageType::Lost) {
            $this->moveTo($lead, PropertyStages::CONTACTED, 'klien yang gugur menulis lagi');

            return;
        }

        if ($this->on('contacted') && $this->stages()->is($lead->stage, PropertyStages::NEW) && $this->firstOutboundId($lead, $message->getKey()) !== null) {
            $this->forward($lead, PropertyStages::CONTACTED, 'klien membalas pesan kita');
        }

        $this->qualify($lead);
    }

    /** A message we sent: the first answer to the client closes the "Hubungi" task of Lead baru. */
    public function replied(Lead $lead, WaMessage $message): void
    {
        if (! $this->on('tasks') || in_array($message->origin, self::NOT_A_REPLY, true) || $message->is_intro) {
            return;
        }

        $lead->loadMissing('stage');

        if ($this->stages()->is($lead->stage, PropertyStages::NEW)) {
            $this->completeTask($lead, $lead->stage, 'balasan pertama terkirim');
        }
    }

    /** The client's purpose, domicile, budget or way of paying changed (the AI's simpan_kebutuhan or an agent). */
    public function needsChanged(Lead $lead): void
    {
        if (! $this->applies()) {
            return;
        }

        $lead->loadMissing('stage');

        if ($this->on('tasks') && filled($lead->purpose) && $this->stages()->is($lead->stage, PropertyStages::CONTACTED)) {
            $this->completeTask($lead, $lead->stage, 'tujuan klien tercatat');
        }

        $this->qualify($lead);
    }

    /** A chat the AI handed to a person: it becomes the agent's task, with the reason. */
    public function handedOff(Lead $lead, string $reason): void
    {
        if (! $this->on('tasks') && ! ($reason === self::BOOKING_REASON && $this->on('booking_ready'))) {
            return;
        }

        $lead->loadMissing('stage');

        if ($lead->stage !== null && ! $lead->stage->isOpen()) {
            return;
        }

        $task = $reason === self::BOOKING_REASON ? self::BOOKING_TASK : mb_substr(self::HANDOFF_TASK.$reason, 0, 160);
        $lead->forceFill(['next_action' => $task, 'next_action_due_at' => now()])->save();
    }

    /**
     * The client cancelled the survey in the chat (the AI's batalkan_survei): back to Terkualifikasi (Dihubungi when
     * the agency has no such step), the date cleared so no H-1 reminder goes out, and a task to offer a new date.
     *
     * @return array{status: string, pesan: string}
     */
    public function surveyCancelled(Lead $lead, string $by, ?string $why = null): array
    {
        $lead->loadMissing('stage');

        if (! $this->on('survey_change') || ! $this->stages()->is($lead->stage, PropertyStages::SURVEY)) {
            return ['status' => 'tidak_berubah', 'pesan' => 'Lead tidak sedang menunggu survei.'];
        }

        $back = $this->stages()->stage(PropertyStages::QUALIFIED) ?? $this->stages()->stage(PropertyStages::CONTACTED);
        $reason = 'klien membatalkan survei lewat chat ('.$by.')'.(filled($why) ? ': '.mb_substr(trim($why), 0, 120) : '');

        if ($back === null) {
            return ['status' => 'gagal', 'pesan' => 'Tahap Terkualifikasi tidak ada.'];
        }

        DB::transaction(function () use ($lead, $back, $reason) {
            ($this->move)($lead, $back, $this->staffActor($lead), [], $reason);
            $lead->forceFill(['survey_at' => null, 'survey_location' => null, 'next_action' => self::CANCELLED_TASK, 'next_action_due_at' => now()->addHour()])->save();
        });

        return ['status' => 'tercatat', 'pesan' => 'Survei dibatalkan, lead kembali ke '.$back->name];
    }

    // ---- The scheduled run ----------------------------------------------------------------------------------

    /** @return array{surveyed: int, silent: int} */
    public function runScheduled(Tenant $tenant): array
    {
        return $this->current->run($tenant, function () {
            $this->stages = null;
            $count = ['surveyed' => 0, 'silent' => 0];

            if (! $this->applies() || PlanLimits::for($this->current->get())->readOnly()) {
                return $count;
            }

            foreach ($this->dueSurveyed() as [$lead, $reason]) {
                if ($this->moveTo($lead, PropertyStages::SURVEYED, $reason)) {
                    $count['surveyed']++;
                    $this->on('thanks') ? $this->thank($lead) : null;
                }
            }

            foreach ($this->dueSilent() as [$lead, $reason]) {
                if ($this->moveTo($lead, PropertyStages::LOST, $reason, $this->lostDetails('Tidak ada respons'))) {
                    $count['silent']++;
                }
            }

            return $count;
        });
    }

    /** @return list<array{0: Lead, 1: string}> leads whose survey time passed long enough ago */
    private function dueSurveyed(): array
    {
        $stage = $this->stages()->stage(PropertyStages::SURVEY);

        if (! $this->on('surveyed') || $stage === null || $this->stages()->stage(PropertyStages::SURVEYED) === null) {
            return [];
        }

        $hours = PropertyStageAutomationSettings::of($this->current->get())->number('surveyed', 'hours');
        $timezone = $this->current->get()->timezone ?: (string) config('app.timezone');

        return Lead::query()->where('stage_id', $stage->getKey())->whereNotNull('survey_at')->where('survey_at', '<=', now()->subHours($hours))->get()
            ->map(fn (Lead $lead) => [$lead, "{$hours} jam setelah jadwal survei ".CarbonImmutable::parse($lead->survey_at)->timezone($timezone)->format('j M H:i')])
            ->all();
    }

    /** @return list<array{0: Lead, 1: string}> leads silent for long enough after we wrote to them */
    private function dueSilent(): array
    {
        if (! $this->on('silent') || $this->stages()->stage(PropertyStages::LOST) === null) {
            return [];
        }

        $settings = PropertyStageAutomationSettings::of($this->current->get());
        $out = [];

        foreach ([PropertyStages::NEW => 'days_new', PropertyStages::CONTACTED => 'days_other', PropertyStages::QUALIFIED => 'days_other'] as $key => $setting) {
            $stage = $this->stages()->stage($key);

            if ($stage === null) {
                continue;
            }

            $days = $settings->number('silent', $setting);

            foreach (Lead::query()->where('stage_id', $stage->getKey())->with('conversation')->get() as $lead) {
                $conversation = $lead->conversation;
                $last = $conversation?->messages()->reorder()->latest('id')->first(['id', 'direction']);

                // Never written to (nobody can call that silence), or the client is the one waiting for an answer.
                if ($last === null || $last->direction === 'in' || ! $conversation->messages()->where('direction', 'out')->exists()) {
                    continue;
                }

                $lastIn = $conversation->last_inbound_at;
                $anchor = $lastIn !== null && $lastIn->gt($lead->stageEnteredAt()) ? $lastIn : $lead->stageEnteredAt();
                $followUp = FollowUpLog::query()->where('lead_id', $lead->getKey())->where('status', 'sent')->max('created_at');

                if ($anchor->lte(now()->subDays($days)) && ($followUp === null || CarbonImmutable::parse($followUp)->lte(now()->subDays(2)))) {
                    $out[] = [$lead, "tidak ada balasan klien {$days} hari setelah follow-up"];
                }
            }
        }

        return $out;
    }

    private function thank(Lead $lead): void
    {
        $conversation = filled($lead->phone) ? WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]) : null;
        $decision = $conversation ? $this->routes->decide($conversation) : null;
        $actor = $this->staffActor($lead);

        if ($actor === null || $decision === null || ! $decision->canSend() || $decision->isPaid()) {
            return;
        }

        $text = strtr(PropertyStageAutomationSettings::of($this->current->get())->thanksMessage(), [
            '{nama}' => $lead->name, '{agensi}' => $this->current->get()->name, '{properti}' => $lead->property?->name ?? '',
        ]);

        try {
            app(SendWhatsAppMessage::class)->from(self::ORIGIN)($conversation, $actor, $text);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ---- "Terapkan ke lead yang ada" ------------------------------------------------------------------------

    /**
     * What the rules would change on the agency's leads today, and with $apply what they do change: the moves to
     * Dihubungi and Terkualifikasi the chats already earned, the tasks already done, the surveys already held,
     * and the silent leads. Leads are only listed, not changed, without $apply.
     *
     * @return list<array{lead: string, change: string, reason: string}>
     */
    public function catchUp(Tenant $tenant, bool $apply): array
    {
        return $this->current->run($tenant, function () use ($apply) {
            $this->stages = null;
            $out = [];

            if (! $this->applies()) {
                return $out;
            }

            $note = function (Lead $lead, string $change, string $reason) use (&$out) {
                $out[] = ['lead' => $lead->name, 'change' => $change, 'reason' => $reason];
            };

            foreach ([PropertyStages::NEW, PropertyStages::CONTACTED] as $key) {
                $stage = $this->stages()->stage($key);

                foreach ($stage ? Lead::query()->where('stage_id', $stage->getKey())->with(['conversation', 'stage'])->get() : [] as $lead) {
                    $answered = $this->firstOutboundId($lead) !== null;
                    $twoWay = $answered && $this->replies($lead) >= 1;
                    $target = $this->qualifies($lead) && $this->on('qualified') ? PropertyStages::QUALIFIED
                        : ($key === PropertyStages::NEW && $twoWay && $this->on('contacted') ? PropertyStages::CONTACTED : null);

                    if ($target !== null) {
                        $name = $this->stages()->stage($target)?->name;
                        $reason = $target === PropertyStages::QUALIFIED ? 'kebutuhan klien sudah tercatat' : 'klien sudah membalas pesan kita';
                        $note($lead, 'pindah ke '.$name, $reason);
                        $apply ? $this->forward($lead, $target, $reason.' (diterapkan ke lead yang ada)') : null;

                        continue;
                    }

                    if ($this->on('tasks') && $key === PropertyStages::NEW && $answered && $this->taskIsDefault($lead, $stage)) {
                        $note($lead, 'tugas "'.$lead->next_action.'" selesai', 'sudah dibalas');
                        $apply ? $this->completeTask($lead, $stage, 'sudah dibalas (diterapkan ke lead yang ada)') : null;
                    }
                }
            }

            foreach ($this->dueSurveyed() as [$lead, $reason]) {
                $note($lead, 'pindah ke '.$this->stages()->stage(PropertyStages::SURVEYED)?->name, $reason);
                $apply ? $this->moveTo($lead, PropertyStages::SURVEYED, $reason) : null;
            }

            foreach ($this->dueSilent() as [$lead, $reason]) {
                $note($lead, 'pindah ke '.$this->stages()->stage(PropertyStages::LOST)?->name, $reason);
                $apply ? $this->moveTo($lead, PropertyStages::LOST, $reason, $this->lostDetails('Tidak ada respons')) : null;
            }

            return $out;
        });
    }

    // ---- Rules ------------------------------------------------------------------------------------------------

    private function qualify(Lead $lead): void
    {
        if ($this->on('qualified') && $this->qualifies($lead)) {
            $this->forward($lead, PropertyStages::QUALIFIED, 'kebutuhan klien tercatat dan klien sudah membalas '.$this->replies($lead).' kali');
        }
    }

    /** A purpose plus where they live, a budget or a way of paying, and at least two answers from the client. */
    private function qualifies(Lead $lead): bool
    {
        $lead->loadMissing('stage');

        return in_array($this->stages()->keyOf($lead->stage), [PropertyStages::NEW, PropertyStages::CONTACTED], true)
            && filled($lead->purpose)
            && (filled($lead->domicile) || filled($lead->budget_min) || filled($lead->budget_max) || filled($lead->getRawOriginal('payment_method')))
            && $this->replies($lead) >= 2;
    }

    /** The first message we sent the client (before $before when given). */
    private function firstOutboundId(Lead $lead, ?int $before = null): ?int
    {
        $conversation = $lead->conversation()->first();

        return $conversation?->messages()->reorder()->where('direction', 'out')->where('is_intro', false)
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))->min('id');
    }

    /** How many times the client wrote after our first message. */
    private function replies(Lead $lead): int
    {
        $first = $this->firstOutboundId($lead);

        return $first === null ? 0 : (int) $lead->conversation()->first()?->messages()->reorder()->where('direction', 'in')->where('id', '>', $first)->count();
    }

    private function taskIsDefault(Lead $lead, ?Stage $stage): bool
    {
        return $stage !== null && filled($lead->next_action) && $lead->next_action === $stage->default_action;
    }

    /** The stage's own task is done: it leaves the task list and the overdue count. */
    private function completeTask(Lead $lead, ?Stage $stage, string $why): void
    {
        if (! $this->taskIsDefault($lead, $stage)) {
            return;
        }

        $task = $lead->next_action;
        $lead->forceFill(['next_action' => null, 'next_action_due_at' => null])->save();
        $lead->activities()->create(['type' => 'task_auto_done', 'body' => "Tugas selesai otomatis: {$task} ({$why})"]);
    }

    private function lostDetails(string $reason): array
    {
        return ['lost_reason_id' => LostReason::query()->whereRaw('lower(label) = ?', [mb_strtolower($reason)])->value('id')
            ?? LostReason::query()->create(['label' => $reason])->getKey()];
    }

    // ---- Moving ------------------------------------------------------------------------------------------

    /** Moves only ahead among open stages: a lead already further along (or closed) stays put. */
    private function forward(Lead $lead, string $key, string $reason): ?string
    {
        $target = $this->stages()->stage($key);
        $lead->loadMissing('stage');
        $current = $lead->stage;

        if ($target === null || ($current !== null && (! $current->isOpen() || $current->is($target) || $current->position >= $target->position))) {
            return null;
        }

        return $this->moveTo($lead, $key, $reason) ? $target->name : null;
    }

    private function moveTo(Lead $lead, string $key, string $reason, array $details = []): bool
    {
        $target = $this->stages()->stage($key);
        $lead->loadMissing('stage');

        if ($target === null || $target->is($lead->stage) || $lead->stage?->type === StageType::Won) {
            return false;
        }

        try {
            ($this->move)($lead, $target, $this->staffActor($lead), $details, $reason);
        } catch (ValidationException $e) {
            report($e);

            return false;
        }

        $lead->setRelation('stage', $target);

        return true;
    }

    private function staffActor(Lead $lead): ?User
    {
        return $lead->owner ?? $this->current->get()?->activeUsers()->wherePivot('role', Role::Admin->value)->first();
    }
}
