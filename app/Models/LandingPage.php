<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'slug', 'status', 'color', 'description', 'property_id', 'blocks', 'published_at'])]
class LandingPage extends Model
{
    use BelongsToTenant;

    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function publicUrl(): string
    {
        return route('landing.show', [$this->tenant->slug, $this->slug]);
    }
}
