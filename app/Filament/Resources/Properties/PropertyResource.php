<?php

namespace App\Filament\Resources\Properties;

use App\Enums\PropertyKind;
use App\Filament\Resources\Properties\Pages\ManageProperties;
use App\Models\Property;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'properti';

    protected static ?string $pluralModelLabel = 'properti';

    protected static ?string $navigationLabel = 'Properti';

    protected static ?int $navigationSort = 7;

    public const STATUSES = ['available' => 'Tersedia', 'booked' => 'Dibooking', 'sold' => 'Terjual'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label('Jenis')->options(PropertyKind::class)->required()->default(PropertyKind::Primary->value),
            TextInput::make('name')->label('Nama proyek atau listing')->required()->maxLength(120),
            TextInput::make('property_type')->label('Tipe')->placeholder('Rumah, Apartemen, Ruko, Tanah')->maxLength(40),
            TextInput::make('location')->label('Lokasi')->maxLength(120),
            TextInput::make('developer')->label('Developer (primary)')->maxLength(120),
            TextInput::make('price_from')->label('Harga mulai (Rp)')->numeric()->minValue(0)->prefix('Rp'),
            Select::make('status')->label('Status')->options(self::STATUSES)->default('available')->required(),
            KeyValue::make('details')->label('Rincian tambahan')->keyLabel('Nama')->valueLabel('Isi')
                ->helperText('Contoh: luas tanah, luas bangunan, sertifikat.')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label('Nama')->searchable()->weight('bold')->description(fn (Property $r) => $r->developer),
            TextColumn::make('kind')->label('Jenis')->badge(),
            TextColumn::make('property_type')->label('Tipe')->toggleable(),
            TextColumn::make('location')->label('Lokasi')->searchable()->toggleable(),
            TextColumn::make('price_from')->label('Harga mulai')->money('IDR', locale: 'id')->sortable(),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'available' => 'success', 'booked' => 'warning', default => 'gray'
                }),
        ])->filters([
            SelectFilter::make('kind')->label('Jenis')->options(PropertyKind::class),
            SelectFilter::make('status')->label('Status')->options(self::STATUSES),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProperties::route('/')];
    }
}
