<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'position'])]
class MediaFolder extends Model
{
    use BelongsToTenant;

    /**
     * Sendable library items grouped by folder, for select boxes: [folder name => [asset id => label]].
     *
     * @return array<string, array<int, string>>
     */
    public static function groupedOptions(): array
    {
        return static::query()->orderBy('position')->with(['assets' => fn ($q) => $q->where('active', true)->orderBy('title')])->get()
            ->filter(fn (self $f) => $f->assets->isNotEmpty())
            ->mapWithKeys(fn (self $f) => [$f->name => $f->assets->mapWithKeys(fn (MediaAsset $a) => [$a->getKey() => $a->title.($a->isLink() ? ' (link)' : '')])->all()])
            ->all();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }
}
