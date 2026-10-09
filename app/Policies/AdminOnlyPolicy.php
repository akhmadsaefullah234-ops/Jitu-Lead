<?php

namespace App\Policies;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Agency settings are the admin's to change; the record must also belong to
 * the tenant the admin is working in.
 */
abstract class AdminOnlyPolicy
{
    public function __construct(protected CurrentTenant $current) {}

    protected function admin(User $user): bool
    {
        return $this->current->roleOf($user) === Role::Admin;
    }

    /** A read-only subscription can look at everything but change nothing. */
    protected function writable(): bool
    {
        return ! PlanLimits::readOnlyNow();
    }

    protected function mine(Model $record): bool
    {
        return $record->getAttribute('tenant_id') === $this->current->id();
    }

    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->admin($user) && $this->mine($record);
    }

    public function create(User $user): bool
    {
        return $this->admin($user) && $this->writable();
    }

    public function update(User $user, Model $record): bool
    {
        return $this->admin($user) && $this->mine($record) && $this->writable();
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
