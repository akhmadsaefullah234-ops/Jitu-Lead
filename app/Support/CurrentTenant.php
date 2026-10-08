<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

/**
 * Holds the tenant for the current request or job. Every tenant-owned model
 * reads it to scope queries and to stamp tenant_id on create.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    /** @var array<int, Role|null> */
    private array $roles = [];

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->roles = [];
    }

    /**
     * The user's role in the current tenant, or null when they are not an
     * active member of it.
     */
    public function roleOf(?User $user): ?Role
    {
        if ($user === null || $this->tenant === null) {
            return null;
        }

        return $this->roles[$user->getKey()] ??= $user->roleIn($this->tenant);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /**
     * Run a callback as another tenant, restoring the previous one afterwards.
     */
    public function run(?Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
