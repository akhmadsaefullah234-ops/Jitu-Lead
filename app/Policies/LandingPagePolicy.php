<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\LandingPage;
use App\Models\User;
use App\Support\CurrentTenant;

/**
 * Landing pages are public marketing: admins and team leaders build them.
 */
class LandingPagePolicy
{
    public function __construct(private CurrentTenant $current) {}

    private function builder(User $user): bool
    {
        return $this->current->roleOf($user)?->seesAllLeads() ?? false;
    }

    private function mine(LandingPage $page): bool
    {
        return $page->tenant_id === $this->current->id();
    }

    public function viewAny(User $user): bool
    {
        return $this->builder($user);
    }

    public function view(User $user, LandingPage $page): bool
    {
        return $this->builder($user) && $this->mine($page);
    }

    public function create(User $user): bool
    {
        return $this->builder($user);
    }

    public function update(User $user, LandingPage $page): bool
    {
        return $this->builder($user) && $this->mine($page);
    }

    public function delete(User $user, LandingPage $page): bool
    {
        return $this->current->roleOf($user) === Role::Admin && $this->mine($page);
    }
}
