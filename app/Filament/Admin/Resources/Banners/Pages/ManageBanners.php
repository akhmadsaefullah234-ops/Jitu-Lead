<?php

namespace App\Filament\Admin\Resources\Banners\Pages;

use App\Filament\Admin\Resources\Banners\BannerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/** Slides shown in the strip at the top of every agency's dashboard. */
class ManageBanners extends ManageRecords
{
    protected static string $resource = BannerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah banner')];
    }
}
