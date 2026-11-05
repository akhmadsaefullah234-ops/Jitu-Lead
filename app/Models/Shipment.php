<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Shipping\Carriers\ShipmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One parcel booked with a courier aggregator for a lead (App\Shipping\Shipments\Shipments). client_order_no is
 * stable per order ("JL-{tenant}-{lead}-{n}"), so a double click or a retry never books twice. events holds the
 * parcel's journey, newest last.
 */
#[Fillable(['lead_id', 'provider', 'client_order_no', 'sequence', 'order_id', 'awb', 'courier', 'rate_code', 'state', 'status', 'raw_status', 'status_reason',
    'is_cod', 'cod_amount', 'price', 'charged', 'weight_grams', 'label_url', 'request', 'events', 'error', 'attempts', 'awb_notified_at', 'tracked_at', 'final_at', 'cancelled_at'])]
class Shipment extends Model
{
    use BelongsToTenant;

    /** Being booked (the order call is queued or running). */
    public const PENDING = 'pending';

    /** Booked, waiting for the tracking number. */
    public const ORDERED = 'ordered';

    /** Has a tracking number. */
    public const SHIPPED = 'shipped';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'is_cod' => 'boolean', 'request' => 'array', 'events' => 'array',
            'awb_notified_at' => 'datetime', 'tracked_at' => 'datetime', 'final_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function notices(): HasMany
    {
        return $this->hasMany(ShipmentNotice::class);
    }

    public function isFinal(): bool
    {
        return $this->final_at !== null || $this->state === self::CANCELLED;
    }

    /** Still open at the courier: not booked yet, or booked and not yet collected. */
    public function cancellable(): bool
    {
        return in_array($this->state, [self::ORDERED, self::SHIPPED], true) && in_array($this->status, [null, ShipmentStatus::AWB_CREATED], true);
    }

    public function needsAction(): bool
    {
        return $this->status === ShipmentStatus::DELIVERY_FAILED && $this->final_at === null;
    }

    /** The newest active (not cancelled or failed) parcel of a lead. */
    public static function latestFor(Lead $lead): ?self
    {
        return static::query()->where('lead_id', $lead->getKey())->whereNotIn('state', [self::CANCELLED, self::FAILED])->latest('id')->first();
    }
}
