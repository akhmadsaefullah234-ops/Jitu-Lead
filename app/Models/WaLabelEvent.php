<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp Business label the pipeline put (or tried to put) on a lead's chat: pending, sent, waiting_label,
 * failed or skipped. Unique per lead and label, so a label is never put on twice (App\WhatsApp\BusinessLabels).
 */
#[Fillable(['lead_id', 'wa_channel_id', 'label', 'status', 'error', 'sent_at', 'waiting_since'])]
class WaLabelEvent extends Model
{
    use BelongsToTenant;

    /** waiting_label: the account has no such label yet; tried again once it has, for up to 7 days. */
    public const STATUSES = ['pending' => 'antre', 'sent' => 'terpasang', 'waiting_label' => 'menunggu label', 'failed' => 'gagal', 'skipped' => 'dilewati'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'waiting_since' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
