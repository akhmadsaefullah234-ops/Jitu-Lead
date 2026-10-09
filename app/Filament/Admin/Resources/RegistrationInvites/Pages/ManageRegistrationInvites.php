<?php

namespace App\Filament\Admin\Resources\RegistrationInvites\Pages;

use App\Filament\Admin\Resources\RegistrationInvites\RegistrationInviteResource;
use App\Models\RegistrationInvite;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;

/** Invite codes only matter when REGISTRATION_MODE=invite. */
class ManageRegistrationInvites extends ManageRecords
{
    protected static string $resource = RegistrationInviteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat kode')->modalHeading('Buat kode undangan')
                ->schema([
                    TextInput::make('max_uses')->label('Boleh dipakai berapa kali')->numeric()->minValue(1)->maxValue(1000)->default(1)->required(),
                    TextInput::make('days')->label('Berlaku (hari, 0 = tanpa batas)')->numeric()->minValue(0)->maxValue(3650)->default(14)->required(),
                    TextInput::make('note')->label('Catatan')->maxLength(120),
                ])
                ->using(fn (array $data) => RegistrationInvite::generate((int) $data['max_uses'], (int) $data['days'] ?: null, $data['note'] ?? null)),
        ];
    }
}
