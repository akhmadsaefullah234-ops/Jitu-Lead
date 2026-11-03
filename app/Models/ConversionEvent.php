<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One conversion event a lead sent from the CRM (a stage it entered, or becoming Hot). Its event_id is unique per
 * agency, so the same event is never sent twice for a lead; results holds what each platform answered.
 */
#[Fillable(['lead_id', 'event_name', 'event_id', 'trigger', 'results'])]
class ConversionEvent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['results' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
