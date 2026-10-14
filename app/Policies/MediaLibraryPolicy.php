<?php

namespace App\Policies;

use App\Billing\PlanLimits;
use App\Enums\Role;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/** Everyone in the agency can use the media library; admins and team leaders keep it, only admins delete from it. */
abstract class MediaLibraryPolicy
{
    public function __construct(private CurrentTenant $current) {}

    private function mine(Model $record): bool
    {
        return $record->getAttribute('tenant_id') === $this->current->id();
    }

    public function viewAny(User $user): bool
    {
        return $this->current->roleOf($user) !== null;
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user) && $this->mine($record);
    }

    public function create(User $user): bool
    {
        return ($this->current->roleOf($user)?->seesAllLeads() ?? false) && ! PlanLimits::readOnlyNow();
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user) && $this->mine($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->current->roleOf($user) === Role::Admin && $this->mine($record) && ! PlanLimits::readOnlyNow();
    }
}
