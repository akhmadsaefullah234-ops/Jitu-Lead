<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The daily follow-up of one parcel the courier could not deliver (App\Shipping\Shipments\ParcelFollowUps): one row
 * per parcel. A new failure starts a new cycle on the same row, so the limit counts every message of the parcel.
 */
#[Fillable(['shipment_id', 'lead_id', 'status', 'failed_at', 'cycle', 'sent_count', 'last_sent_at', 'last_attempt_on', 'state', 'stop_reason', 'stopped_at', 'attention_at', 'attention_reason'])]
class ParcelFollowUp extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const STOPPED = 'stopped';

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime', 'last_sent_at' => 'datetime', 'stopped_at' => 'datetime', 'attention_at' => 'datetime',
            'cycle' => 'integer', 'sent_count' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
