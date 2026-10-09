<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The agency's ad-tracking ids. The ids go into public pages; the API tokens
 * for server-side events are encrypted and never sent back to a form.
 */
#[Fillable(['meta_pixel_id', 'tiktok_pixel_id', 'google_tag_id', 'google_ads_label', 'credentials'])]
#[Hidden(['credentials'])]
class TrackingSetting extends Model
{
    use BelongsToTenant;

    public const SECRETS = ['meta_capi_token', 'tiktok_events_token'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function hasAny(): bool
    {
        return filled($this->meta_pixel_id) || filled($this->tiktok_pixel_id) || filled($this->google_tag_id);
    }
}
