<?php

namespace App\Models;

use App\LandingPages\BlockFormat;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'slug', 'status', 'color', 'description', 'property_id', 'blocks', 'published_at', 'font', 'logo', 'whatsapp_number', 'meta_title', 'og_image', 'template'])]
class LandingPage extends Model
{
    use BelongsToTenant;

    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** Three safe choices, all system fonts so nothing is downloaded: label and CSS stacks (body, heading). */
    public const FONTS = [
        'modern' => ['Modern (bersih)', 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'],
        'elegan' => ['Elegan (judul berkaki)', 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'Georgia, "Times New Roman", serif'],
        'ramah' => ['Ramah (membulat)', 'ui-rounded, "Trebuchet MS", "Segoe UI", system-ui, sans-serif', 'ui-rounded, "Trebuchet MS", "Segoe UI", system-ui, sans-serif'],
    ];

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

    /** @return list<array{type: string, version: int, props: array<string, mixed>}> */
    public function sections(): array
    {
        return BlockFormat::normalize($this->blocks);
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
