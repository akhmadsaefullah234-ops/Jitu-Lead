<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A slide in the banner strip at the top of every agency's dashboard. Set by
 * the super admin, so it is not tenant-owned.
 */
#[Fillable(['title', 'image_path', 'link_url', 'is_active', 'sort_order', 'starts_at', 'ends_at'])]
class Banner extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Banner $b) => Storage::disk('public')->delete($b->image_path));

        static::updated(function (Banner $b) {
            if ($b->wasChanged('image_path') && $b->getOriginal('image_path')) {
                Storage::disk('public')->delete($b->getOriginal('image_path'));
            }
        });
    }

    /** Active, and inside its start and end dates when it has them. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function imageUrl(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }
}
