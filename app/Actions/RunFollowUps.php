<?php

namespace App\Actions;

use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use App\WhatsApp\RouteKind;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\TemplateRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sends the automatic follow-ups that have come due for one agency.
 *
 * A rule fires once per lead and stage, a given number of hours after the lead
 * entered that stage. It stays quiet when the lead has written back since, when
 * only a paid Meta template could carry the message, or outside the sending
 * hours; those leads are looked at again on the next run.
 */
class RunFollowUps
{
    /** Leads whose follow-up is later than this past due are left alone, e.g. after an outage. */
    public const STALE_AFTER_HOURS = 72;

    public function __construct(
        private CurrentTenant $current,
        private SendWhatsAppMessage $send,
        private RouteSelector $routes,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function __invoke(Tenant $tenant, int $limit = 50): array
    {
        return $this->current->run($tenant, function () use ($tenant, $limit) {
            $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
            $touched = [];
            $hour = (int) now($tenant->timezone ?: config('app.timezone'))->format('G');

            $rules = FollowUpRule::query()->where('active', true)->orderBy('delay_hours')->get();

            foreach ($rules as $rule) {
                if (! $rule->allowsHour($hour)) {
                    continue;
                }

                foreach ($this->due($rule) as $lead) {
                    if ($limit <= $result['sent'] + $result['failed']) {
                        return $result;
                    }

                    // One automatic message per lead per run; the next rule waits for its turn.
                    if (isset($touched[$lead->getKey()])) {
                        continue;
                    }

                    $outcome = $this->handle($tenant, $rule, $lead);

                    if ($outcome !== null) {
                        $result[$outcome]++;
                        $touched[$lead->getKey()] = true;
                    }
                }
            }

            return $result;
        });
    }

    /** @return Collection<int, Lead> */
    private function due(FollowUpRule $rule)
    {
        $anchor = 'coalesce(leads.stage_entered_at, leads.created_at)';

        return Lead::query()
            ->open()
            ->whereNotNull('phone')
            ->when($rule->stage_id, fn (Builder $q) => $q->where('stage_id', $rule->stage_id))
            ->whereRaw("$anchor <= ?", [now()->subHours($rule->delay_hours)])
            ->whereRaw("$anchor >= ?", [now()->subHours($rule->delay_hours + self::STALE_AFTER_HOURS)])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('follow_up_logs')
                ->whereColumn('follow_up_logs.lead_id', 'leads.id')
                ->whereColumn('follow_up_logs.stage_id', 'leads.stage_id')
                ->where('follow_up_logs.follow_up_rule_id', $rule->getKey()))
            ->with(['owner', 'property'])
            ->orderBy('id')
            ->get();
    }

    /** @return 'sent'|'failed'|'skipped'|null Null means the lead stays queued for a later run. */
    private function handle(Tenant $tenant, FollowUpRule $rule, Lead $lead): ?string
    {
        $conversation = WaConversation::query()->firstOrCreate(['lead_id' => $lead->getKey()], ['phone' => $lead->phone]);

        if ($conversation->last_inbound_at !== null && $conversation->last_inbound_at->greaterThan($lead->stageEnteredAt())) {
            return $this->log($rule, $lead, 'skipped', 'Lead sudah membalas');
        }

        $decision = $this->routes->decide($conversation);

        if (! $decision->canSend() || $decision->kind === RouteKind::PaidTemplate) {
            return null;
        }

        $actor = $this->actorFor($tenant, $lead);

        if ($actor === null) {
            return null;
        }

        $text = $this->renderer->render($rule->body, $lead, $actor, $tenant->name)['text'];
        $message = ($this->send)($conversation, $actor, $text);

        return $message->status === MessageStatus::Failed
            ? $this->log($rule, $lead, 'failed', (string) $message->error)
            : $this->log($rule, $lead, 'sent', $rule->name);
    }

    private function actorFor(Tenant $tenant, Lead $lead): ?User
    {
        return $lead->owner ?? $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->first();
    }

    private function log(FollowUpRule $rule, Lead $lead, string $status, ?string $note): string
    {
        FollowUpLog::query()->create([
            'follow_up_rule_id' => $rule->getKey(),
            'lead_id' => $lead->getKey(),
            'stage_id' => $lead->stage_id,
            'status' => $status,
            'note' => $note !== null ? mb_substr($note, 0, 250) : null,
        ]);

        return $status;
    }
}
