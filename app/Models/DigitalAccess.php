<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buyer's access to a paid digital product (/akses/{token}): valid until expires_at and for max_downloads
 * downloads, revoked on Refund or by hand. The token is never written to the lead's activity or given to the AI.
 */
class DigitalAccess extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'opened_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'revoked_at' => 'datetime',
            'downloads' => 'integer', 'max_downloads' => 'integer', 'resends' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function expired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function exhausted(): bool
    {
        return $this->downloads >= $this->max_downloads;
    }

    /** Still opens and still downloads. */
    public function usable(): bool
    {
        return $this->revoked_at === null && ! $this->expired() && ! $this->exhausted();
    }

    /** Why the link no longer works, in the buyer's and the admin's words; null while it does. */
    public function problem(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'dicabut',
            $this->expired() => 'kedaluwarsa',
            $this->exhausted() => 'batas unduhan habis',
            default => null,
        };
    }
}
