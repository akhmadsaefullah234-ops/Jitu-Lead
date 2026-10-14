<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;

/**
 * Picks who gets the next lead (PRD F4). Everyone who takes leads (see Tenant::leadRecipients: agents,
 * plus an admin or team leader who joined the rotation) gets a share: the percentage set for them in the
 * Team menu, or an equal share when none is set. The next lead goes to whoever is furthest below their
 * share over the last 30 days, and a tie goes to whoever was given a lead longest ago. Inactive members
 * are skipped because only active memberships are considered. An agency with nobody in the rotation
 * (a solo owner, say) gives its leads to its longest-standing active Admin, so a lead from a form,
 * WhatsApp or an import never lands without an owner.
 */
class AssignLead
{
    private const WINDOW_DAYS = 30;

    public function __construct(private CurrentTenant $current) {}

    /** @param  array<int, int>  $except  User ids that must not get it, e.g. who already let this lead sit */
    public function nextAgent(array $except = []): ?User
    {
        $tenant = $this->current->get();

        if ($tenant === null) {
            return null;
        }

        $pool = $tenant->leadRecipients()->reject(fn (User $u) => in_array($u->getKey(), $except, true));
        $weights = $this->weights($pool);
        $pool = $pool->filter(fn (User $u) => $weights[$u->getKey()] > 0)->values();

        if ($pool->isNotEmpty()) {
            return $this->mostBehind($pool, $weights);
        }

        // Nobody is left in the rotation. Falling back to the admin is for brand-new leads only;
        // a lead that is being moved on stays put instead (see ReassignLead).
        return $except === [] ? $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->orderBy('tenant_user.id')->first() : null;
    }

    /**
     * Each person's share in percent for the given people. With no percentages set everyone gets the same.
     *
     * @param  Collection<int, User>  $people
     * @return array<int, float>
     */
    public function weights(Collection $people): array
    {
        $anySet = $people->contains(fn (User $u) => $u->pivot->lead_share !== null);

        return $people->mapWithKeys(fn (User $u) => [
            $u->getKey() => (float) ($anySet ? ($u->pivot->lead_share ?? 0) : 1),
        ])->all();
    }

    /** @param  Collection<int, User>  $pool */
    private function mostBehind(Collection $pool, array $weights): User
    {
        $total = array_sum($weights);
        $since = now()->subDays(self::WINDOW_DAYS);
        $recent = Lead::withTrashed()->whereIn('owner_id', $pool->modelKeys())->where('created_at', '>=', $since)
            ->selectRaw('owner_id, count(*) as n')->groupBy('owner_id')->pluck('n', 'owner_id');
        $handed = $recent->sum();

        return $pool->sortBy(fn (User $u) => [
            -(($weights[$u->getKey()] / $total) * ($handed + 1) - ($recent[$u->getKey()] ?? 0)),
            Lead::withTrashed()->where('owner_id', $u->getKey())->max('created_at') ?? '',
            $u->getKey(),
        ])->first();
    }
}
