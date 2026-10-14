<?php

namespace App\Actions;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Push\Alerts;
use App\Support\CurrentTenant;

/**
 * Moves a lead on to another agent, by hand (an admin or team leader) or automatically when the owner
 * has not reacted in time. Whoever already had the lead is skipped, so it does not bounce back, and
 * every move is written to the lead's history. A lead stays where it is when nobody else is left.
 */
class ReassignLead
{
    public function __construct(private AssignLead $assign, private CurrentTenant $current, private Alerts $alerts) {}

    /** Moves one lead to $to, or to the next agent in line; returns the new owner, or null when nobody was available. */
    public function __invoke(Lead $lead, ?User $by = null, ?User $to = null, ?string $reason = null): ?User
    {
        $from = $lead->owner;
        $to ??= $this->assign->nextAgent($this->alreadyHad($lead));

        if ($to === null || $to->getKey() === $lead->owner_id) {
            return null;
        }

        $lead->owner_id = $to->getKey();
        $lead->reassign_count++;
        $lead->save();

        $lead->activities()->create([
            'user_id' => $by?->getKey(),
            'type' => 'reassigned',
            'body' => 'Lead dipindah'.($from ? " dari {$from->name}" : '')." ke {$to->name}".($reason ? " ({$reason})" : ''),
            'meta' => ['from' => $from?->getKey(), 'to' => $to->getKey(), 'auto' => $by === null],
        ]);

        $this->alerts->leadMoved($lead);

        return $to;
    }

    /**
     * Automatic pass for one agency: every open lead whose owner stayed silent for the agency's
     * waiting time goes to the next agent, up to the agency's limit of moves per lead.
     *
     * @return int how many leads were moved
     */
    public function stale(Tenant $tenant): int
    {
        if ($tenant->leadReassignMode() !== 'auto') {
            return 0;
        }

        return $this->current->run($tenant, function () use ($tenant) {
            $moved = 0;

            Lead::query()->unattended($tenant->leadWaitMinutes())
                ->where('reassign_count', '<', $tenant->leadMaxMoves())
                ->with('owner')->orderBy('id')->each(function (Lead $lead) use ($tenant, &$moved) {
                    if (($this)($lead, reason: "belum ditanggapi {$tenant->leadWaitMinutes()} menit") !== null) {
                        $moved++;
                    }
                });

            return $moved;
        });
    }

    /** @return array<int, int> */
    private function alreadyHad(Lead $lead): array
    {
        $earlier = $lead->activities()->where('type', 'reassigned')->get()->pluck('meta.from')->filter()->all();

        return array_values(array_unique(array_map('intval', [...$earlier, $lead->owner_id])));
    }
}
