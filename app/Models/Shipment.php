<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Shipping\Carriers\ShipmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One parcel booked with a courier aggregator for a lead (App\Shipping\Shipments\Shipments). client_order_no is
 * stable per order ("JL-{tenant}-{lead}-{n}"), so a double click or a retry never books twice. events holds the
 * parcel's journey, newest last.
 */
#[Fillable(['lead_id', 'provider', 'client_order_no', 'sequence', 'order_id', 'awb', 'courier', 'rate_code', 'state', 'status', 'raw_status', 'status_reason',
    'is_cod', 'cod_amount', 'price', 'charged', 'weight_grams', 'label_url', 'request', 'events', 'error', 'attempts', 'awb_notified_at', 'tracked_at', 'final_at', 'cancelled_at',
    'provider_order_no', 'cod_fee', 'cod_disbursement', 'cashback', 'source', 'track_failures', 'untrackable_at', 'linked_at', 'label_path', 'label_saved_at', 'label_attempts', 'label_failed_at', 'printed_at', 'printed_by',
    'print_count', 'automatic', 'balance_wait_at', 'balance_needed'])]
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

    /** Booked through JITU LEAD. */
    public const AUTO = 'auto';

    /** A tracking number made elsewhere (the provider's own app) and linked to the lead: no order to cancel or label. */
    public const MANUAL = 'manual';

    /** Tracking that does not know a linked number stops after this many tries. */
    public const MAX_TRACK_FAILURES = 3;

    protected function casts(): array
    {
        return [
            'is_cod' => 'boolean', 'request' => 'array', 'events' => 'array',
            'awb_notified_at' => 'datetime', 'tracked_at' => 'datetime', 'final_at' => 'datetime', 'cancelled_at' => 'datetime',
            'untrackable_at' => 'datetime', 'linked_at' => 'datetime', 'label_saved_at' => 'datetime', 'label_failed_at' => 'datetime',
            'printed_at' => 'datetime', 'automatic' => 'boolean', 'balance_wait_at' => 'datetime', 'balance_needed' => 'integer',
            'cod_fee' => 'integer', 'cod_disbursement' => 'integer', 'cashback' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    /** Has a label to print: booked here (not a linked number) with a tracking number, not cancelled. */
    public function printable(): bool
    {
        return ! $this->manual() && filled($this->awb) && $this->state === self::SHIPPED;
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
    /**
     * "Coba lagi": refused by the provider before it took the order (sent again as it is), or booked without a
     * tracking number yet and stuck (only the number is asked for again: never a second order).
     */
    public function retryable(): bool
    {
        return ! $this->manual() && (($this->state === self::FAILED && $this->order_id === null)
            || ($this->state === self::ORDERED && blank($this->awb) && filled($this->error))
            || $this->waitingBalance());
    }

    /**
     * "Menunggu saldo": held because the provider's balance does not cover the shipping, either before the order
     * was made (no order at the provider) or when its tracking number was asked for. Tried again by itself once the
     * balance is enough (shipping:balance-wait), or with "Coba lagi"; never booked a second time.
     */
    public function waitingBalance(): bool
    {
        return $this->balance_wait_at !== null && in_array($this->state, [self::PENDING, self::ORDERED], true) && blank($this->awb);
    }

    public function cancellable(): bool
    {
        return $this->source !== self::MANUAL && ((in_array($this->state, [self::ORDERED, self::SHIPPED], true) && in_array($this->status, [null, ShipmentStatus::AWB_CREATED], true))
            || ($this->state === self::PENDING && $this->waitingBalance()));
    }

    public function manual(): bool
    {
        return $this->source === self::MANUAL;
    }

    /** The provider does not know this linked number; tracking stopped. */
    public function untrackable(): bool
    {
        return $this->untrackable_at !== null;
    }

    public function needsAction(): bool
    {
        return $this->status === ShipmentStatus::DELIVERY_FAILED && $this->final_at === null;
    }

    /** Tabs of the Pengiriman menu. */
    public const TABS = [
        'awaiting' => 'Menunggu resi',
        'balance' => 'Menunggu saldo',
        'unprinted' => 'Belum dicetak',
        'printed' => 'Sudah dicetak, menunggu dijemput',
        'transit' => 'Dalam perjalanan',
        'action' => 'Perlu tindakan',
        'done' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    /** The parcels of one tab of the Pengiriman menu. */
    public function scopeTab(Builder $query, string $tab): Builder
    {
        $waiting = fn (Builder $q) => $q->where('state', self::SHIPPED)->whereNotNull('awb')->whereNull('final_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', ShipmentStatus::AWB_CREATED));

        return match ($tab) {
            'unprinted' => $waiting($query)->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', self::MANUAL))
                ->whereNull('printed_at')->where(fn ($q) => $q->whereNotNull('label_path')->orWhereNull('label_failed_at')),
            'printed' => $waiting($query)->where(fn ($q) => $q->whereNotNull('printed_at')->orWhere('source', self::MANUAL)),
            'transit' => $query->where('state', self::SHIPPED)->whereNull('final_at')
                ->whereIn('status', [ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY]),
            'awaiting' => $query->whereIn('state', [self::PENDING, self::ORDERED])->whereNull('error'),
            'balance' => $query->whereIn('state', [self::PENDING, self::ORDERED])->whereNotNull('balance_wait_at')->whereNull('awb'),
            'action' => $query->whereNull('balance_wait_at')->where(fn ($q) => $q->where('state', self::FAILED)
                ->orWhere(fn ($q) => $q->whereIn('state', [self::PENDING, self::ORDERED])->whereNotNull('error'))
                ->orWhereIn('status', [ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING, ShipmentStatus::RETURNED])
                ->orWhere(fn ($q) => $q->where('state', self::SHIPPED)->whereNull('printed_at')->whereNull('label_path')->whereNotNull('label_failed_at'))),
            'done' => $query->where('status', ShipmentStatus::DELIVERED),
            'cancelled' => $query->where('state', self::CANCELLED),
            default => $query,
        };
    }

    /** The newest active (not cancelled or failed) parcel of a lead. */
    public static function latestFor(Lead $lead): ?self
    {
        return static::query()->where('lead_id', $lead->getKey())->whereNotIn('state', [self::CANCELLED, self::FAILED])->latest('id')->first();
    }
}
