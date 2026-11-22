<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One iPaymu invoice (QRIS or virtual account) for a digital purchase. A lead can have several when one expired. */
class DigitalPayment extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const LABELS = [self::PENDING => 'Menunggu', self::PAID => 'Lunas', self::FAILED => 'Gagal', self::EXPIRED => 'Kedaluwarsa'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'paid_at' => 'datetime', 'reminded_at' => 'datetime', 'last_callback_at' => 'datetime', 'access_opened_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Still waiting and the payment page is still open. */
    public function isOpen(): bool
    {
        return $this->status === self::PENDING && filled($this->payment_url) && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
