<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A digital-product agency's own iPaymu account (Pengaturan > Pembayaran otomatis). The VA and API key are
 * encrypted at rest and hidden from arrays and JSON; they are only read to sign requests and callbacks.
 */
#[Fillable(['provider', 'va', 'api_key', 'sandbox', 'active', 'invoice_hours', 'access_days'])]
#[Hidden(['va', 'api_key'])]
class PaymentGateway extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'va' => 'encrypted',
            'api_key' => 'encrypted',
            'sandbox' => 'boolean',
            'active' => 'boolean',
            'invoice_hours' => 'integer',
            'access_days' => 'integer',
        ];
    }

    public function ready(): bool
    {
        return $this->active && filled($this->va) && filled($this->api_key);
    }
}
