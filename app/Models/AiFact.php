<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One fact about the agency's property (App\Ai\Facts), with its source and the owner's review. */
#[Fillable(['subject', 'property_id', 'key', 'value', 'source', 'source_name', 'status'])]
class AiFact extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WIZARD = 'wizard';

    public const DOCUMENT = 'dokumen';

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
