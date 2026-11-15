<?php

namespace App\FollowUps;

use App\Billing\PlanLimits;
use App\Models\FollowUpRule;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaConversation;
use Carbon\CarbonInterface;

/**
 * A property agency's built-in follow-ups switched on in one click (Follow-up > "Nyalakan follow-up properti"): the
 * starter sequence from config/industries.php (Sapaan H+1, Tanya kebutuhan H+3, Tawarkan survei H+7) and the survey
 * reminder H-1. A starter rule the agency already has is switched on as it is, its wording kept; one it deleted is
 * made again with the built-in text, when its stage still exists and the plan allows another rule. A sequence step
 * stops for a lead once the client wrote back (as for every agency) or once a person took the chat over
 * (tookOver). Runs inside the tenant.
 */
class PropertyFollowUps
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** @return list<array{name: string, stage: string, days: int, body: string}> */
    public function starters(): array
    {
        return array_map(fn (array $r) => ['name' => $r[0], 'stage' => $r[1], 'days' => (int) $r[2], 'body' => $r[3]],
            (array) $this->tenant->industry()->config('follow_ups', []));
    }

    /**
     * Switches the built-in follow-ups on.
     *
     * @return array{on: list<string>, missing: list<string>} the rules now on, and those that could not be made
     */
    public function enable(): array
    {
        $on = [];
        $missing = [];

        foreach ($this->starters() as $starter) {
            $stageId = Stage::query()->where('name', $starter['stage'])->value('id');

            if ($stageId === null) {
                $missing[] = "{$starter['name']} (tahap \"{$starter['stage']}\" tidak ada)";

                continue;
            }

            $rule = FollowUpRule::query()->whereNull('property_id')->where('name', $starter['name'])->where('stage_id', $stageId)->first();

            if ($rule === null) {
                if (PlanLimits::for($this->tenant)->denyAdding('followups') !== null) {
                    $missing[] = "{$starter['name']} (batas follow-up paket tercapai)";

                    continue;
                }

                $rule = FollowUpRule::query()->create(['name' => $starter['name'], 'stage_id' => $stageId, 'delay_days' => $starter['days'], 'body' => $starter['body'], 'active' => false]);
            }

            $rule->update(['active' => true]);
            $on[] = $starter['name'];
        }

        SurveyReminder::rule()->update(['active' => true]);
        $on[] = SurveyReminder::DEFAULTS['name'];

        return ['on' => $on, 'missing' => $missing];
    }

    /** Whether every built-in follow-up is on already. */
    public function enabled(): bool
    {
        if (! SurveyReminder::orDefaults()->active) {
            return false;
        }

        foreach ($this->starters() as $starter) {
            $on = FollowUpRule::query()->whereNull('property_id')->where('name', $starter['name'])->where('active', true)
                ->whereHas('stage', fn ($q) => $q->where('name', $starter['stage']))->exists();

            if (! $on) {
                return false;
            }
        }

        return true;
    }

    /**
     * A person answered the client since $since: from the Inbox (origin "agent") or from the WhatsApp app on the
     * phone. Messages the AI, a follow-up, the welcome or a shipping notice sent do not count.
     */
    public static function tookOver(WaConversation $conversation, CarbonInterface $since): bool
    {
        return $conversation->messages()->where('direction', 'out')->where('status', '!=', 'failed')
            ->where(fn ($q) => $q->where('from_phone', true)->orWhere('origin', self::AGENT_ORIGIN))
            ->where('sent_at', '>', $since)->exists();
    }

    /** The origin of a message a person sent from the Inbox. */
    public const AGENT_ORIGIN = 'agent';

    /** Only a property agency's sequence stops when a person took over; a shop's reminders (payment) still go. */
    public static function stopsOnTakeover(Tenant $tenant): bool
    {
        return $tenant->industry()->key === 'properti';
    }
}
