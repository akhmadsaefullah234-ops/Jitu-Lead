<?php

namespace App\Filament\Admin\Resources\RegistrationInvites;

use App\Filament\Admin\Resources\RegistrationInvites\Pages\ManageRegistrationInvites;
use App\Models\RegistrationInvite;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RegistrationInviteResource extends Resource
{
    protected static ?string $model = RegistrationInvite::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $modelLabel = 'kode undangan';

    protected static ?string $pluralModelLabel = 'Kode undangan';

    protected static ?string $navigationLabel = 'Kode undangan';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('code')->label('Kode')->state(fn (RegistrationInvite $r) => $r->display())->copyable()->fontFamily('mono'),
            TextColumn::make('note')->label('Catatan')->placeholder('-'),
            TextColumn::make('uses')->label('Terpakai')->state(fn (RegistrationInvite $r) => "{$r->uses} / {$r->max_uses}"),
            TextColumn::make('expires_at')->label('Berlaku sampai')->dateTime('d M Y H:i')->placeholder('Tanpa batas'),
        ])->recordActions([DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRegistrationInvites::route('/')];
    }
}
