<?php

namespace App\Filament\Resources\WaChannels\Pages;

use App\Enums\WaChannelType;
use App\Filament\Resources\WaChannels\WaChannelResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWaChannel extends CreateRecord
{
    protected static string $resource = WaChannelResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        $gateway = $this->record->type === WaChannelType::Gateway;

        return Notification::make()->success()->title('Nomor ditambahkan')
            ->body($gateway ? 'Klik "Scan QR" di baris nomor ini untuk menghubungkan WhatsApp.' : 'Klik "Tes koneksi" setelah Anda mengisi webhook di Meta.');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = WaChannelResource::withCredentials($data);
        $data['status'] = 'disconnected';

        return $data;
    }
}
