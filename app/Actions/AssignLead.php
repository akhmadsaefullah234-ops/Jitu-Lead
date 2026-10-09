<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use App\Support\CurrentTenant;

/**
 * Round-robin assignment (PRD F4): the active agent who was given a new lead
 * longest ago gets the next one. Inactive agents and agents on leave are skipped
 * because only active memberships are considered. An agency with no active agent
 * (a solo owner, say) gives its leads to its longest-standing active Admin, so a
 * lead from a form, WhatsApp or an import never lands without an owner.
 */
class AssignLead
{
    public function __construct(private CurrentTenant $current) {}

    public function nextAgent(): ?User
    {
        $tenant = $this->current->get();

        if ($tenant === null) {
            return null;
        }

        $agent = $tenant->agents()
            ->get()
            ->sortBy(fn (User $agent) => [
                Lead::withTrashed()->where('owner_id', $agent->getKey())->max('created_at') ?? '',
                $agent->getKey(),
            ])
            ->first();

        return $agent ?? $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->orderBy('tenant_user.id')->first();
    }
}
