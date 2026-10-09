<?php

namespace App\Filament\Resources\LostReasons;

use App\Filament\Resources\LostReasons\Pages\ManageLostReasons;
use App\Models\LostReason;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LostReasonResource extends Resource
{
    protected static ?string $model = LostReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedXCircle;

    protected static ?string $modelLabel = 'alasan gugur';

    protected static ?string $pluralModelLabel = 'alasan gugur';

    protected static ?string $navigationLabel = 'Alasan gugur';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->label('Alasan')->required()->maxLength(80),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id')->columns([
            TextColumn::make('label')->label('Alasan')->searchable(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLostReasons::route('/')];
    }
}
