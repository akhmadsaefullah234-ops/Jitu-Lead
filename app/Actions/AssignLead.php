<?php

namespace App\Actions;

use App\Models\Lead;
use App\Models\User;
use App\Support\CurrentTenant;

/**
 * Round-robin assignment (PRD F4): the active agent who was given a new lead
 * longest ago gets the next one. Inactive agents and agents on leave are skipped
 * because only active memberships are considered.
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

        return $tenant->agents()
            ->get()
            ->sortBy(fn (User $agent) => [
                Lead::withTrashed()->where('owner_id', $agent->getKey())->max('created_at') ?? '',
                $agent->getKey(),
            ])
            ->first();
    }
}
