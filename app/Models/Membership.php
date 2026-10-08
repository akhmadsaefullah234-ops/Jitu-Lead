<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Membership extends Pivot
{
    protected $table = 'tenant_user';

    public $incrementing = true;

    protected static function booted(): void
    {
        // Enforced here, not only in the form, so no screen can leave an agency without an admin.
        static::updating(function (Membership $membership) {
            if (($membership->isDirty('role') || $membership->isDirty('status'))
                && $membership->getOriginal('role') === Role::Admin
                && $membership->getOriginal('status') === 'active'
                && ! static::query()->where('tenant_id', $membership->tenant_id)->where('role', Role::Admin->value)
                    ->where('status', 'active')->whereKeyNot($membership->getKey())->exists()) {
                throw new \LogicException('An agency must keep at least one active admin.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * An agency must always keep one active admin, or nobody can manage it.
     */
    public function isLastActiveAdmin(): bool
    {
        return $this->role === Role::Admin && $this->status === 'active'
            && ! static::query()->where('tenant_id', $this->tenant_id)->where('role', Role::Admin->value)
                ->where('status', 'active')->whereKeyNot($this->getKey())->exists();
    }
}
