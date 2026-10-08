<?php

namespace App\Filament\Resources\WaChannels\Pages;

use App\Filament\Resources\WaChannels\WaChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWaChannels extends ListRecords
{
    protected static string $resource = WaChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
