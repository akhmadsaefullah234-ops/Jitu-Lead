<?php

namespace App\Policies;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Models\Property;
use App\Models\User;
use App\Support\CurrentTenant;

/**
 * Everyone in the agency can look up listings; admins and team leaders keep
 * the catalogue, only admins delete from it.
 */
class PropertyPolicy
{
    public function __construct(private CurrentTenant $current) {}

    private function role(User $user): ?Role
    {
        return $this->current->roleOf($user);
    }

    private function mine(Property $property): bool
    {
        return $property->tenant_id === $this->current->id();
    }

    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function view(User $user, Property $property): bool
    {
        return $this->role($user) !== null && $this->mine($property);
    }

    public function create(User $user): bool
    {
        return ($this->role($user)?->seesAllLeads() ?? false) && ! PlanLimits::readOnlyNow();
    }

    public function update(User $user, Property $property): bool
    {
        return $this->create($user) && $this->mine($property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $this->role($user) === Role::Admin && $this->mine($property) && ! PlanLimits::readOnlyNow();
    }
}
