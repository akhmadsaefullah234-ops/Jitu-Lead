<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Shipping\Shipments\LabelSheet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** One print run of the "Pengiriman" menu: the merged PDF (kept config('carriers.label_batch_keep_days')), who printed it and which parcels. */
#[Fillable(['user_id', 'count', 'packing_list', 'file', 'shipment_ids'])]
class LabelBatch extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['packing_list' => 'boolean', 'shipment_ids' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function path(): string
    {
        return LabelSheet::DIR.'/'.$this->tenant_id.'/'.$this->file;
    }

    public function available(): bool
    {
        return Storage::disk('local')->exists($this->path());
    }
}
