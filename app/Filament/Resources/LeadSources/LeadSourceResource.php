<?php

namespace App\Filament\Resources\LeadSources;

use App\Filament\Resources\LeadSources\Pages\ManageLeadSources;
use App\Models\LeadSource;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeadSourceResource extends Resource
{
    protected static ?string $model = LeadSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $modelLabel = 'sumber lead';

    protected static ?string $pluralModelLabel = 'sumber lead';

    protected static ?string $navigationLabel = 'Sumber lead';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 11;

    public const TYPES = [
        'ads' => 'Iklan',
        'portal' => 'Portal properti',
        'form' => 'Formulir web',
        'whatsapp' => 'WhatsApp',
        'manual' => 'Manual',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama sumber')->required()->maxLength(60),
            Select::make('type')->label('Jenis')->options(self::TYPES)->required()->default('manual'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label('Sumber')->searchable(),
            TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLeadSources::route('/')];
    }
}
