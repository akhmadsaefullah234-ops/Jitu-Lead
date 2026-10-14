<?php

namespace App\Filament\Resources\MediaFolders\Pages;

use App\Filament\Resources\MediaFolders\MediaFolderResource;
use App\Models\MediaFolder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMediaFolders extends ManageRecords
{
    protected static string $resource = MediaFolderResource::class;

    public function getSubheading(): ?string
    {
        return 'Folder hanya bisa dihapus bila sudah kosong. Pindahkan atau hapus isinya dulu di Pustaka media.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Folder baru')->mutateDataUsing(fn (array $data) => $data + ['position' => (int) MediaFolder::query()->max('position') + 1])];
    }
}
