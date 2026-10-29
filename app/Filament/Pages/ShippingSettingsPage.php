<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Shipping\Regions;
use App\Shipping\ShippingSettings;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Where a shop sends from, its couriers, how parcels are weighed and the zone price table. The AI's cek_ongkir
 * tool and the inbox "Hitung ongkir" button price parcels from this; nothing calls a courier API yet.
 */
class ShippingSettingsPage extends Page
{
    protected string $view = 'filament.pages.shipping-settings';

    protected static ?string $slug = 'pengiriman';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Pengiriman';

    protected static ?string $title = 'Pengiriman';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 13;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->roleOf(auth()->user()) === Role::Admin && $current->get()?->industry()->key === 'retail';
    }

    public function mount(): void
    {
        $settings = ShippingSettings::of(app(CurrentTenant::class)->get())->toArray();
        $settings['couriers'] = array_values($settings['couriers'] ?? []);

        $this->form->fill($settings);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Asal dan kurir')->columns(2)->schema([
                Toggle::make('enabled')->label('Hitung ongkir dari tabel zona')->columnSpanFull()
                    ->helperText('Bila aktif, AI memakai tabel ini untuk menjawab ongkir dan agen bisa memakai tombol "Hitung ongkir" di Inbox.'),
                TextInput::make('origin')->label('Alamat asal pengiriman')->maxLength(200)->placeholder('Kedungwaringin, Kab. Bekasi, Jawa Barat')->columnSpanFull(),
                TagsInput::make('couriers')->label('Kurir dan layanan')->placeholder('Ketik lalu Enter, mis. JNE REG')->live()->columnSpanFull()
                    ->required(fn (Get $get) => (bool) $get('enabled'))
                    ->helperText('Nama di sini dipakai di kolom tarif setiap zona.'),
            ]),
            Section::make('Aturan berat')->columns(2)->schema([
                TextInput::make('default_weight_grams')->label('Berat bawaan per produk')->numeric()->integer()->minValue(1)->maxValue(100000)->suffix('g')->required()
                    ->helperText('Dipakai bila produk di katalog belum diisi beratnya.'),
                TextInput::make('volumetric_divisor')->label('Pembagi berat volume')->numeric()->integer()->minValue(1000)->maxValue(10000)->required()
                    ->helperText('Berat volume = P × L × T (cm) / angka ini. Umumnya 6000.'),
            ])->description('Berat tertagih = yang lebih besar antara berat asli dan berat volume, dibulatkan ke atas per kg (minimal 1 kg).'),
            Section::make('Zona dan tarif')->description('Zona dicek dari atas: zona yang mencantumkan kota tujuan dipakai lebih dulu, lalu zona yang mencantumkan provinsinya.')->schema([
                Repeater::make('zones')->hiddenLabel()->addActionLabel('Tambah zona')->reorderable()->collapsible()->collapsed()->cloneable()
                    ->itemLabel(fn (array $state) => ($state['name'] ?? null) ?: 'Zona baru')
                    ->schema([
                        TextInput::make('name')->label('Nama zona')->required()->maxLength(80),
                        Select::make('provinces')->label('Provinsi')->multiple()->options(Regions::options())->searchable(),
                        TagsInput::make('cities')->label('Kota/kabupaten yang ikut zona ini')->placeholder('Mis. Bekasi')
                            ->helperText('Untuk zona yang lintas provinsi, mis. Bekasi dan Tangerang di Jabodetabek.'),
                        TagsInput::make('excluded_cities')->label('Kecuali kota/kabupaten')->placeholder('Mis. Bogor'),
                        Toggle::make('confirm_admin')->label('Konfirmasi admin (tanpa angka)')->live()
                            ->helperText('AI tidak menyebut ongkir untuk zona ini dan menyerahkan chat ke agen.'),
                        Grid::make(2)->hidden(fn (Get $get) => (bool) $get('confirm_admin'))->schema([
                            TextInput::make('eta_min')->label('Estimasi tercepat')->numeric()->integer()->minValue(0)->maxValue(60)->suffix('hari'),
                            TextInput::make('eta_max')->label('Estimasi terlama')->numeric()->integer()->minValue(0)->maxValue(60)->suffix('hari')->gte('eta_min'),
                        ]),
                        Repeater::make('rates')->label('Tarif per kg')->addActionLabel('Tambah tarif kurir')->defaultItems(0)
                            ->hidden(fn (Get $get) => (bool) $get('confirm_admin'))
                            ->columns(2)->schema([
                                Select::make('courier')->label('Kurir')->required()
                                    ->options(fn (Get $get) => collect($get('../../../../couriers') ?? [])->mapWithKeys(fn ($c) => [$c => $c])->all()),
                                TextInput::make('per_kg')->label('Tarif per kg')->numeric()->integer()->minValue(0)->prefix('Rp')->required(),
                            ]),
                    ]),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Simpan')->action('save')];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $tenant = app(CurrentTenant::class)->get();

        $tenant->forceFill(['shipping' => [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'provider' => ShippingSettings::of($tenant)->provider(),
            'origin' => filled($state['origin'] ?? null) ? trim($state['origin']) : null,
            'couriers' => array_values(array_unique(array_map('trim', $state['couriers'] ?? []))),
            'default_weight_grams' => (int) $state['default_weight_grams'],
            'volumetric_divisor' => (int) $state['volumetric_divisor'],
            'zones' => array_values(array_map(fn (array $z) => [
                'name' => trim($z['name']),
                'provinces' => array_values($z['provinces'] ?? []),
                'cities' => array_values($z['cities'] ?? []),
                'excluded_cities' => array_values($z['excluded_cities'] ?? []),
                'rates' => ($z['confirm_admin'] ?? false) ? [] : array_values(array_map(fn (array $r) => ['courier' => $r['courier'], 'per_kg' => (int) $r['per_kg']], $z['rates'] ?? [])),
                'eta_min' => filled($z['eta_min'] ?? null) ? (int) $z['eta_min'] : null,
                'eta_max' => filled($z['eta_max'] ?? null) ? (int) $z['eta_max'] : null,
                'confirm_admin' => (bool) ($z['confirm_admin'] ?? false),
            ], $state['zones'] ?? [])),
        ]])->save();

        Notification::make()->title('Pengaturan pengiriman disimpan')->success()->send();
    }
}
