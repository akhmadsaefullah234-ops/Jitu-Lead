<?php

namespace App\Policies;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;

/**
 * Numbers and their credentials are the agency's to manage: admins only.
 */
class WaChannelPolicy
{
    public function __construct(private CurrentTenant $current) {}

    private function admin(User $user): bool
    {
        return $this->current->roleOf($user) === Role::Admin;
    }

    private function mine(WaChannel $channel): bool
    {
        return $channel->tenant_id === $this->current->id();
    }

    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function view(User $user, WaChannel $channel): bool
    {
        return $this->admin($user) && $this->mine($channel);
    }

    public function create(User $user): bool
    {
        return $this->admin($user) && ! PlanLimits::readOnlyNow();
    }

    public function update(User $user, WaChannel $channel): bool
    {
        return $this->admin($user) && $this->mine($channel) && ! PlanLimits::readOnlyNow();
    }

    public function delete(User $user, WaChannel $channel): bool
    {
        return $this->update($user, $channel);
    }
}
