<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The buyer's WhatsApp about one parcel status (App\Shipping\Shipments\StatusNotices): one row per parcel and status, so never twice. */
#[Fillable(['shipment_id', 'status', 'state', 'event_at', 'due_at', 'note', 'wa_message_id'])]
class ShipmentNotice extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['event_at' => 'datetime', 'due_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
