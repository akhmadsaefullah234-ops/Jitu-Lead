<?php

namespace App\Filament\Resources\WaChannels\Pages;

use App\Filament\Resources\WaChannels\WaChannelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWaChannel extends EditRecord
{
    protected static string $resource = WaChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** Fill the plain settings back in, never the secrets. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $credentials = $this->record->credentials ?? [];
        $data['cred'] = array_diff_key($credentials, array_flip(WaChannelResource::SECRETS));

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['type']);

        return WaChannelResource::withCredentials($data, $this->record);
    }
}
