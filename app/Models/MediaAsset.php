<?php

namespace App\Models;

use App\Media\Library;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['folder_id', 'title', 'description', 'kind', 'path', 'original_name', 'size_bytes', 'url', 'active'])]
class MediaAsset extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $asset) {
            if ($asset->path) {
                Storage::disk('local')->delete($asset->path);
            }
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function isLink(): bool
    {
        return $this->kind === 'link';
    }

    /** The text sent for a link: its title and address. */
    public function linkText(): string
    {
        return trim($this->title."\n".$this->url);
    }

    public function whatsAppType(): string
    {
        return Library::whatsAppType((string) $this->original_name);
    }
}
