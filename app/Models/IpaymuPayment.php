<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One iPaymu payment session for a plan request. A request can have several when an earlier one expired. */
class IpaymuPayment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const LABELS = [self::PENDING => 'Menunggu', self::PAID => 'Berhasil', self::FAILED => 'Gagal', self::EXPIRED => 'Kedaluwarsa'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_escrow' => 'boolean', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'last_callback_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SubscriptionRequest::class, 'subscription_request_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Still waiting and the payment page is still open. */
    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->payment_url && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function label(): string
    {
        return (self::LABELS[$this->status] ?? $this->status).($this->status === self::PAID && $this->is_escrow ? ' (escrow)' : '');
    }
}
