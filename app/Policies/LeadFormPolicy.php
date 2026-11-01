<?php

namespace App\Policies;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Models\LeadForm;
use App\Models\User;
use App\Support\CurrentTenant;

/**
 * Forms are public marketing like landing pages: admins and team leaders build them. The main form cannot be deleted.
 */
class LeadFormPolicy
{
    public function __construct(private CurrentTenant $current) {}

    private function builder(User $user): bool
    {
        return $this->current->roleOf($user)?->seesAllLeads() ?? false;
    }

    private function mine(LeadForm $form): bool
    {
        return $form->tenant_id === $this->current->id();
    }

    public function viewAny(User $user): bool
    {
        return $this->builder($user);
    }

    public function view(User $user, LeadForm $form): bool
    {
        return $this->builder($user) && $this->mine($form);
    }

    public function create(User $user): bool
    {
        return $this->builder($user) && ! PlanLimits::readOnlyNow();
    }

    public function update(User $user, LeadForm $form): bool
    {
        return $this->builder($user) && $this->mine($form) && ! PlanLimits::readOnlyNow();
    }

    public function delete(User $user, LeadForm $form): bool
    {
        return $this->current->roleOf($user) === Role::Admin && $this->mine($form) && ! $form->is_primary && ! PlanLimits::readOnlyNow();
    }
}
