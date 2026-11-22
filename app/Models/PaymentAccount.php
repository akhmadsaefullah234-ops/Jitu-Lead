<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu rekening bank tenant untuk pembayaran transfer (Pengaturan > Pembayaran). Nomor rekening bukan rahasia,
 * tetapi tidak ditulis ke log dan tidak pernah dibaca AI: instruksi bayar disusun sistem (App\Payments\PaymentInstructions).
 */
#[Fillable(['bank_name', 'account_number', 'account_holder', 'active', 'sort_order'])]
class PaymentAccount extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Rekening aktif, urut seperti di pengaturan (teratas dulu). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** "BCA 1234567890 a.n. Quicker Sport" */
    public function line(): string
    {
        return trim($this->bank_name).' '.trim($this->account_number).' a.n. '.trim($this->account_holder);
    }
}
