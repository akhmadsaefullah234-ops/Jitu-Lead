<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->using(Membership::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function activeTenants(): BelongsToMany
    {
        return $this->tenants()->wherePivot('status', 'active');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // The owner's panel is for super admins only. In the agency panel, tenant
        // access is checked per tenant in canAccessTenant(); a new user with no
        // tenant yet still needs the panel to register their agency.
        return $panel->getId() === 'admin' ? (bool) $this->is_super_admin : true;
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->activeTenants()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        // A suspended agency is locked out by the platform owner.
        return $tenant->status !== 'suspended' && $this->activeTenants()->whereKey($tenant->getKey())->exists();
    }

    /**
     * The user's role in a tenant, or null when they are not an active member.
     */
    public function roleIn(?Tenant $tenant): ?Role
    {
        if ($tenant === null) {
            return null;
        }

        $membership = $this->activeTenants()->whereKey($tenant->getKey())->first()?->pivot;

        return $membership?->role;
    }
}
