<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An agency's request to start or change a plan, waiting for the platform owner to confirm the transfer. */
class SubscriptionRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
