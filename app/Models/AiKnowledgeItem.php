<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'question', 'content', 'source', 'active', 'property_id'])]
class AiKnowledgeItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** The product this entry is about; none means it is for every product. */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
