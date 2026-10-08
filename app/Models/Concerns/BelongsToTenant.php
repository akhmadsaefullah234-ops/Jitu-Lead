<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Scopes every query to the current tenant and stamps tenant_id on create.
 *
 * With no tenant set (console, super admin tooling) the scope is not applied,
 * so code running outside a request must set a tenant or query explicitly.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(CurrentTenant::class)->id();

            if ($tenantId !== null) {
                $query->where($query->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function ($model) {
            $tenantId = app(CurrentTenant::class)->id();

            if ($model->tenant_id === null) {
                $model->tenant_id = $tenantId;
            }

            if ($model->tenant_id === null) {
                throw new LogicException('Cannot create '.static::class.' without a tenant.');
            }

            if ($tenantId !== null && (int) $model->tenant_id !== $tenantId) {
                throw new LogicException('Cannot create '.static::class.' for another tenant.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('The tenant of '.static::class.' cannot change.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
