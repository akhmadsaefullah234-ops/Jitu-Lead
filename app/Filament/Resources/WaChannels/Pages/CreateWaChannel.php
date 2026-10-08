<?php

namespace App\Filament\Resources\WaChannels\Pages;

use App\Filament\Resources\WaChannels\WaChannelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWaChannel extends CreateRecord
{
    protected static string $resource = WaChannelResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = WaChannelResource::withCredentials($data);
        $data['status'] = 'disconnected';

        return $data;
    }
}
