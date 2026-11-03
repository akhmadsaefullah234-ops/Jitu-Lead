<?php

namespace App\Filament\Resources\WaChannels\Pages;

use App\Filament\Resources\WaChannels\WaChannelResource;
use App\WhatsApp\BusinessLabels;
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
        $data['label_settings'] = BusinessLabels::settings($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['type']);

        if (isset($data['label_settings'])) {
            // Saved through the same normalising as reading; the labels loaded from WhatsApp stay.
            $known = BusinessLabels::settings($this->record)['known'];
            $this->record->label_settings = (array) $data['label_settings'];
            $data['label_settings'] = ['known' => $known] + BusinessLabels::settings($this->record);
        }

        return WaChannelResource::withCredentials($data, $this->record);
    }
}
