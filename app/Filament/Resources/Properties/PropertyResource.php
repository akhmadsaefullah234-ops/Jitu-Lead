<?php

namespace App\Filament\Resources\Properties;

use App\Enums\PropertyKind;
use App\Filament\Resources\Properties\Pages\ManageProperties;
use App\Models\Property;
use App\Support\Industry;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static ?int $navigationSort = 8;

    public const STATUSES = ['available' => 'Tersedia', 'booked' => 'Dibooking', 'sold' => 'Terjual'];

    /** The same statuses in shop words */
    public const RETAIL_STATUSES = ['available' => 'Tersedia', 'booked' => 'Stok menipis', 'sold' => 'Habis'];

    // The catalogue holds properties or products depending on the agency's industry (config/industries.php).
    public static function getNavigationLabel(): string
    {
        return Industry::t('catalog');
    }

    public static function getModelLabel(): string
    {
        return Industry::t('catalog_item');
    }

    public static function getPluralModelLabel(): string
    {
        return Industry::t('catalog_item');
    }

    public static function getNavigationIcon(): string
    {
        return Industry::t('catalog_icon');
    }

    private static function isProperty(): bool
    {
        return Industry::current()->key === 'properti';
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return self::isProperty() ? self::STATUSES : self::RETAIL_STATUSES;
    }

    public static function form(Schema $schema): Schema
    {
        $property = self::isProperty();

        return $schema->components([
            Select::make('kind')->label('Jenis')->options(PropertyKind::class)->required()->default(PropertyKind::Primary->value)->visible($property),
            TextInput::make('name')->label(Industry::t('catalog_item_name'))->required()->maxLength(120),
            TextInput::make('property_type')->label($property ? 'Tipe' : 'Kategori')
                ->placeholder($property ? 'Rumah, Apartemen, Ruko, Tanah' : 'Kaos, Hijab, Skincare')->maxLength(40),
            TextInput::make('location')->label('Lokasi')->maxLength(120)->visible($property),
            TextInput::make('developer')->label('Developer (primary)')->maxLength(120)->visible($property),
            TextInput::make('price_from')->label($property ? 'Harga mulai (Rp)' : 'Harga (Rp)')->numeric()->minValue(0)->prefix('Rp'),
            Select::make('status')->label($property ? 'Status' : 'Stok')->options(self::statuses())->default('available')->required(),
            // Shipping cost is counted from these (Pengaturan > Pengiriman)
            TextInput::make('weight_grams')->label('Berat (gram)')->numeric()->integer()->minValue(1)->maxValue(1000000)->suffix('g')->visible(! $property)
                ->helperText('Berat siap kirim, termasuk kemasan. Kosong = berat bawaan di pengaturan Pengiriman.'),
            Grid::make(3)->columnSpanFull()->visible(! $property)->schema([
                TextInput::make('length_cm')->label('Panjang (cm)')->numeric()->integer()->minValue(1)->maxValue(1000),
                TextInput::make('width_cm')->label('Lebar (cm)')->numeric()->integer()->minValue(1)->maxValue(1000),
                TextInput::make('height_cm')->label('Tinggi (cm)')->numeric()->integer()->minValue(1)->maxValue(1000),
            ]),
            KeyValue::make('details')->label('Rincian tambahan')->keyLabel('Nama')->valueLabel('Isi')
                ->helperText($property ? 'Contoh: luas tanah, luas bangunan, sertifikat.' : 'Contoh: ukuran, warna, bahan, berat.')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $property = self::isProperty();

        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label('Nama')->searchable()->weight('bold')->description(fn (Property $r) => $r->developer),
            TextColumn::make('kind')->label('Jenis')->badge()->visible($property),
            TextColumn::make('property_type')->label($property ? 'Tipe' : 'Kategori')->toggleable(),
            TextColumn::make('location')->label('Lokasi')->searchable()->toggleable()->visible($property),
            TextColumn::make('weight_grams')->label('Berat')->suffix(' g')->toggleable()->visible(! $property),
            TextColumn::make('price_from')->label($property ? 'Harga mulai' : 'Harga')->money('IDR', locale: 'id')->sortable(),
            TextColumn::make('status')->label($property ? 'Status' : 'Stok')->badge()
                ->formatStateUsing(fn (string $state) => self::statuses()[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'available' => 'success', 'booked' => 'warning', default => 'gray'
                }),
        ])->filters([
            SelectFilter::make('kind')->label('Jenis')->options(PropertyKind::class)->visible($property),
            SelectFilter::make('status')->label($property ? 'Status' : 'Stok')->options(self::statuses()),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProperties::route('/')];
    }
}
