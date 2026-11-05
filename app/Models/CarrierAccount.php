<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An agency's account at a courier aggregator (Pengaturan > Pengiriman > Everpro). The credentials (client key,
 * secret, callback key) are encrypted and never shown again; callback_token is the agency's own part of the
 * callback URL, so one agency's URL can never feed another's parcels.
 */
#[Fillable(['provider', 'sandbox', 'credentials', 'settings', 'messages', 'balance', 'balance_at'])]
#[Hidden(['credentials'])]
class CarrierAccount extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(fn (self $account) => $account->callback_token ??= Str::random(40));
    }

    protected function casts(): array
    {
        return ['sandbox' => 'boolean', 'credentials' => 'encrypted:array', 'settings' => 'array', 'messages' => 'array', 'balance_at' => 'datetime'];
    }

    public function credential(string $key): ?string
    {
        $value = ((array) $this->credentials)[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get((array) $this->settings, $key, $default);
    }

    /** Client key and secret are in: calls can be made. */
    public function hasCredentials(): bool
    {
        return $this->credential('client_key') !== null && $this->credential('client_secret') !== null;
    }
}
