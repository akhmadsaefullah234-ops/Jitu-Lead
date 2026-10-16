<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One trial email for one agency (sent, or skipped because a later one replaced it). Not tenant-scoped: the daily run reads every agency. */
class TrialEmailLog extends Model
{
    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['anchor' => 'date'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
