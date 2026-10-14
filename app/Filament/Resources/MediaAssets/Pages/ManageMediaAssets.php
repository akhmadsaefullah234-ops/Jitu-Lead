<?php

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Resources\MediaAssets\MediaAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Storage;

class ManageMediaAssets extends ManageRecords
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah ke pustaka')->mutateDataUsing(fn (array $data) => static::prepare($data))];
    }

    /** A link carries no file; a file records its size. */
    public static function prepare(array $data): array
    {
        if (($data['kind'] ?? 'file') === 'link') {
            return $data + ['path' => null, 'original_name' => null, 'size_bytes' => 0] + ['url' => null];
        }

        $data['url'] = null;
        $data['size_bytes'] = isset($data['path']) ? (int) Storage::disk('local')->size($data['path']) : 0;

        return $data;
    }
}
