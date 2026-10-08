<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use App\Support\CurrentTenant;

/**
 * Admins and team leaders work on every lead in their tenant; agents only on
 * the leads they own. Nobody reaches a lead outside their current tenant.
 */
class LeadPolicy
{
    public function __construct(private CurrentTenant $current) {}

    public function viewAny(User $user): bool
    {
        return $this->current->roleOf($user) !== null;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->canWork($user, $lead);
    }

    public function create(User $user): bool
    {
        return $this->current->roleOf($user) !== null;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->canWork($user, $lead);
    }

    public function reassign(User $user, Lead $lead): bool
    {
        return $this->inTenant($lead) && ($this->current->roleOf($user)?->seesAllLeads() ?? false);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->inTenant($lead) && $this->current->roleOf($user) === Role::Admin;
    }

    public function deleteAny(User $user): bool
    {
        return $this->current->roleOf($user) === Role::Admin;
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $this->delete($user, $lead);
    }

    public function restoreAny(User $user): bool
    {
        return $this->deleteAny($user);
    }

    public function forceDelete(User $user, Lead $lead): bool
    {
        return $this->delete($user, $lead);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->deleteAny($user);
    }

    private function canWork(User $user, Lead $lead): bool
    {
        $role = $this->current->roleOf($user);

        if ($role === null || ! $this->inTenant($lead)) {
            return false;
        }

        return $role->seesAllLeads() || (int) $lead->owner_id === (int) $user->getKey();
    }

    private function inTenant(Lead $lead): bool
    {
        return (int) $lead->tenant_id === $this->current->id();
    }
}
