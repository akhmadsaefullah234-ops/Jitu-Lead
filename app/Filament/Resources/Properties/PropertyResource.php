<?php

namespace App\Filament\Resources\Properties;

use App\Enums\PropertyKind;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\ManageProperties;
use App\Models\Property;
use App\Support\Industry;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * On the product page the details and "Iklan & promo" are two tabs of one form: each tab shows its own fields,
     * and saving from either keeps the other's. The create dialog shows both.
     */
    public static function form(Schema $schema): Schema
    {
        $property = self::isProperty();
        $onTab = fn (bool $promo) => fn ($livewire) => ! $livewire instanceof EditProperty || ($livewire->activeRelationManager === EditProperty::PROMO) === $promo;

        return $schema->components([
            Grid::make(2)->columnSpanFull()->visible($onTab(false))->dehydratedWhenHidden()->schema([
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
            ]),
            Section::make('Iklan & promo')->columnSpanFull()->columns(2)->visible($onTab(true))->dehydratedWhenHidden()
                ->heading(fn ($livewire) => $livewire instanceof EditProperty ? null : 'Iklan & promo')
                ->schema([
                    TextInput::make('compare_price')->label('Harga coret (Rp)')->numeric()->minValue(0)->prefix('Rp')
                        ->helperText('Opsional. Harga normal sebelum promo, ditampilkan dicoret; harga di tab Detail menjadi harga promo.')
                        ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                            if (filled($value) && filled($get('price_from')) && (int) $value <= (int) $get('price_from')) {
                                $fail('Harga coret harus lebih tinggi dari harga.');
                            }
                        }),
                    TextInput::make('promo_text')->label('Teks promo')->maxLength(300)->columnSpanFull()
                        ->placeholder($property ? 'Free biaya KPR dan AJB sampai akhir bulan' : 'Diskon 30% minggu ini, gratis ongkir Pulau Jawa')
                        ->helperText('Opsional. AI menyebutnya saat membahas harga '.Industry::t('catalog_item').' ini.'),
                    TagsInput::make('ad_keywords')->label('Pengenal iklan')->columnSpanFull()->placeholder('Ketik lalu Enter')
                        ->splitKeys([','])->nestedRecursiveRules(['string', 'max:120'])
                        ->helperText('Kata kunci, potongan teks sapaan iklan, atau ID iklan Meta (angka). Chat dari iklan atau pesan pertama yang memuatnya otomatis ditandai menanyakan '.Industry::t('catalog_item').' ini, mis. "badminton", "capra", "selempang", atau 120210000000001.'),
                ]),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $property = self::isProperty();

        return $table->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'knowledgeItems as knowledge_count',
                'followUpRules as follow_ups_count' => fn ($q) => $q->where('active', true),
            ]))
            ->recordUrl(fn (Property $r) => self::getUrl('edit', ['record' => $r]))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->weight('bold')->description(fn (Property $r) => $r->developer),
                TextColumn::make('kind')->label('Jenis')->badge()->visible($property),
                TextColumn::make('property_type')->label($property ? 'Tipe' : 'Kategori')->toggleable(),
                TextColumn::make('location')->label('Lokasi')->searchable()->toggleable()->visible($property),
                TextColumn::make('weight_grams')->label('Berat')->suffix(' g')->toggleable()->visible(! $property),
                TextColumn::make('price_from')->label($property ? 'Harga mulai' : 'Harga')->money('IDR', locale: 'id')->sortable()
                    ->description(fn (Property $r) => $r->compare_price ? 'Coret: Rp '.number_format((int) $r->compare_price, 0, ',', '.') : null),
                TextColumn::make('ad_keywords')->label('Pengenal iklan')->badge()->limitList(3)->toggleable(),
                TextColumn::make('knowledge_count')->label('Pengetahuan')->alignCenter()->toggleable()
                    ->formatStateUsing(fn ($state) => $state ?: '-')
                    ->url(fn (Property $r) => self::getUrl('edit', ['record' => $r, 'relation' => EditProperty::KNOWLEDGE])),
                TextColumn::make('follow_ups_count')->label('Follow-up')->alignCenter()->toggleable()
                    ->tooltip('Aturan follow-up aktif khusus '.Industry::t('catalog_item').' ini')
                    ->formatStateUsing(fn ($state) => $state ?: '-')
                    ->url(fn (Property $r) => self::getUrl('edit', ['record' => $r, 'relation' => EditProperty::FOLLOW_UPS])),
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
        return [
            'index' => ManageProperties::route('/'),
            'edit' => EditProperty::route('/{record}/edit'),
        ];
    }
}
