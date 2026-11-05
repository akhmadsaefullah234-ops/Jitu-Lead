<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\CarrierAccount;
use App\Regions\RegionDirectory;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Regions;
use App\Shipping\Shipments\StatusNotices;
use App\Shipping\ShippingSettings;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Where a shop sends from, its couriers, how parcels are weighed and the zone price table. The AI's cek_ongkir
 * tool and the inbox "Hitung ongkir" button price parcels from this.
 *
 * Below it, automatic shipping (App\Shipping\Carriers, Everpro first): the agency's credentials (encrypted, never
 * shown again), sandbox or production, the sender and its origin in the provider's place list, the default
 * courier, the callback URL to paste in the provider's dashboard, and the buyer's messages per parcel status.
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

    /** @var array<string, mixed> */
    public ?array $carrier = [];

    /** @var array<string, mixed> */
    public ?array $notices = [];

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
        $this->fillCarrier();
    }

    private function account(): ?CarrierAccount
    {
        return CarrierAccount::query()->first();
    }

    private function fillCarrier(): void
    {
        $account = $this->account();

        $this->carrierForm->fill([
            'provider' => $account?->provider,
            'sandbox' => $account?->sandbox ?? true,
            'client_key' => null, 'client_secret' => null, 'callback_key' => null,
            'sender_name' => $account?->setting('sender_name') ?? app(CurrentTenant::class)->get()->name,
            'sender_phone' => $account?->setting('sender_phone'),
            'sender_address' => $account?->setting('sender_address'),
            'origin_id' => $account?->setting('origin_id'),
            'service' => $account?->setting('service') ?? 'JNE REG',
            'shipment_type' => $account?->setting('shipment_type') ?? 'PICKUP',
            'insurance' => (bool) $account?->setting('insurance', false),
        ]);

        $this->noticesForm->fill(StatusNotices::settings($account));
    }

    public function carrierForm(Schema $schema): Schema
    {
        $stored = fn (string $key) => $this->account()?->credential($key) !== null;
        $secret = fn (string $name, string $label) => TextInput::make($name)->label($label)->password()->revealable()->maxLength(255)->autocomplete('new-password')
            ->placeholder(fn () => $stored($name) ? 'Tersimpan (ketik untuk mengganti)' : null)
            ->required(fn (Get $get) => filled($get('provider')) && ! $stored($name) && $name !== 'callback_key');

        return $schema->statePath('carrier')->components([
            Section::make('Pengiriman otomatis')->columns(2)
                ->description('Buat resi, label, jemput paket, dan pelacakan langsung dari JITU LEAD lewat penyedia pengiriman. Kosongkan penyedia untuk mematikan; menu resi otomatis lalu disembunyikan.')
                ->schema([
                    Select::make('provider')->label('Penyedia')->options(Carriers::options())->placeholder('Tidak dipakai')->live(),
                    Toggle::make('sandbox')->label('Mode sandbox (uji coba)')->inline(false)
                        ->helperText('Matikan setelah uji coba berhasil untuk memakai production.')->visible(fn (Get $get) => filled($get('provider'))),
                    $secret('client_key', 'Client key')->visible(fn (Get $get) => filled($get('provider'))),
                    $secret('client_secret', 'Client secret')->visible(fn (Get $get) => filled($get('provider'))),
                    $secret('callback_key', 'Callback key (x-everpro-key)')->visible(fn (Get $get) => filled($get('provider')))
                        ->helperText('Kunci yang Anda isi di dashboard Everpro > Pengaturan > Konfigurasi. Callback tanpa kunci ini ditolak.'),
                    Text::make(fn () => $this->callbackInfo())->columnSpanFull()->visible(fn (Get $get) => filled($get('provider'))),
                ]),
            Section::make('Pengirim')->columns(2)->visible(fn (Get $get) => filled($get('provider')))->schema([
                TextInput::make('sender_name')->label('Nama toko')->maxLength(100)->required(fn (Get $get) => filled($get('provider'))),
                TextInput::make('sender_phone')->label('Nomor HP pengirim')->tel()->maxLength(20)->required(fn (Get $get) => filled($get('provider'))),
                Textarea::make('sender_address')->label('Alamat lengkap pengirim')->rows(2)->maxLength(300)->columnSpanFull()->required(fn (Get $get) => filled($get('provider'))),
                Select::make('origin_id')->label('Lokasi asal (kelurahan)')->columnSpanFull()->searchable()
                    ->helperText('Ketik kode pos (paling cepat) atau nama kelurahan/kecamatan, lalu pilih. Simpan kredensial dulu agar pencarian berjalan.')
                    ->getSearchResultsUsing(fn (string $search) => $this->searchLocations($search))
                    ->getOptionLabelUsing(fn ($value) => $this->account()?->setting('origin_id') == $value ? ($this->account()->setting('origin_label') ?: $value) : (cache()->get('carrier-loc-label:'.$value) ?? $value)),
            ]),
            Section::make('Kurir bawaan')->columns(3)->visible(fn (Get $get) => filled($get('provider')))->schema([
                TextInput::make('service')->label('Kurir dan layanan')->maxLength(60)->placeholder('JNE REG')
                    ->helperText('Dipakai bila pembeli belum memilih kurir.'),
                Select::make('shipment_type')->label('Cara serah paket')->options(['PICKUP' => 'Dijemput kurir (PICKUP)', 'DROP' => 'Antar ke agen (DROP)'])->native(false)->selectablePlaceholder(false),
                Toggle::make('insurance')->label('Pakai asuransi')->inline(false),
            ]),
        ]);
    }

    public function noticesForm(Schema $schema): Schema
    {
        $fields = [];

        foreach (StatusNotices::DEFAULTS as $status => $default) {
            $warn = fn (string $key) => Text::make(fn (Get $get) => 'Variabel tidak dikenal: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', StatusNotices::unknown((string) $get($key))))
                .'. Tidak akan terkirim apa adanya: baris "Label: {variabel}" dihapus, selain itu diganti "-".')
                ->color('danger')->visible(fn (Get $get) => StatusNotices::unknown((string) $get($key)) !== []);

            $fields[] = Section::make(ShipmentStatus::label($status))->compact()->collapsible()->schema(array_filter([
                Toggle::make("{$status}.enabled")->label('Kirim ke pembeli')->live(),
                Textarea::make("{$status}.text")->label(isset($default['text_cod']) ? 'Teks (bukan COD)' : 'Teks')->rows(3)->maxLength(1000)->live(debounce: 500)
                    ->visible(fn (Get $get) => (bool) $get("{$status}.enabled")),
                $warn("{$status}.text"),
                isset($default['text_cod']) ? Textarea::make("{$status}.text_cod")->label('Teks untuk COD')->rows(3)->maxLength(1000)->live(debounce: 500)
                    ->visible(fn (Get $get) => (bool) $get("{$status}.enabled")) : null,
                isset($default['text_cod']) ? $warn("{$status}.text_cod") : null,
            ]));
        }

        return $schema->statePath('notices')->components([
            Section::make('Pesan status kiriman')
                ->description('Dikirim sekali per status per resi lewat nomor WhatsApp pemilik lead, tanpa pesan perkenalan. Jam 21.00 sampai 05.00 pesan menunggu sampai 05.00. '
                    .'Paket dikembalikan tidak pernah dikabarkan ke pembeli; agen diberi notifikasi. Resi terbit memakai pesan resi yang sudah ada (Otomatisasi tahap). '
                    .'Variabel: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', StatusNotices::VARIABLES)).'.')
                ->schema($fields),
        ]);
    }

    private function callbackInfo(): HtmlString
    {
        $account = $this->account();

        if ($account === null) {
            return new HtmlString('<span style="color:var(--gray-500)">URL callback muncul setelah pengaturan disimpan.</span>');
        }

        $url = route('webhooks.shipping', ['provider' => $account->provider, 'token' => $account->callback_token]);
        $balance = $account->balance !== null ? 'Rp'.number_format($account->balance, 0, ',', '.').' (dicek '.$account->balance_at?->timezone(app(CurrentTenant::class)->get()->timezone ?: 'Asia/Jakarta')->format('d M H:i').')' : 'belum dicek';

        return new HtmlString('<div style="display:grid;gap:.35rem;font-size:.875rem">'
            .'<div><strong>URL callback</strong> (salin ke dashboard Everpro > Pengaturan > Konfigurasi, untuk AWB dan status pengiriman):</div>'
            .'<code style="word-break:break-all;padding:.4rem .6rem;border-radius:.4rem;background:var(--gray-100)">'.e($url).'</code>'
            .'<div><strong>Saldo:</strong> '.e($balance).' · <strong>Mode:</strong> '.($account->sandbox ? 'sandbox' : 'production').'</div></div>');
    }

    /** @return array<string, string> */
    private function searchLocations(string $search): array
    {
        $account = $this->account();

        if ($account === null || ! $account->hasCredentials() || mb_strlen(trim($search)) < 3) {
            return [];
        }

        $digits = preg_replace('/\D/', '', $search);

        try {
            $places = Carriers::make($account)->locations(strlen($digits) === 5 ? $digits : null, strlen($digits) === 5 ? null : $search);
        } catch (CarrierException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return [];
        }

        return collect($places)->take(50)->mapWithKeys(function (CarrierLocation $l) {
            cache()->put('carrier-loc-label:'.$l->id, $l->label(), now()->addHour());
            cache()->put('carrier-loc:'.$l->id, $l->toArray(), now()->addHour());

            return [$l->id => $l->label()];
        })->all();
    }

    public function saveCarrier(): void
    {
        $state = $this->carrierForm->getState();
        $account = $this->account();

        if (blank($state['provider'] ?? null)) {
            $account?->delete();
            Notification::make()->title('Pengiriman otomatis dimatikan')->success()->send();
            $this->fillCarrier();

            return;
        }

        $account ??= new CarrierAccount;
        $credentials = (array) ($account->credentials ?? []);

        foreach (['client_key', 'client_secret', 'callback_key'] as $key) {
            if (filled($state[$key] ?? null)) {
                $credentials[$key] = trim((string) $state[$key]);
            }
        }

        $settings = (array) ($account->settings ?? []);
        $place = filled($state['origin_id'] ?? null) ? cache()->get('carrier-loc:'.$state['origin_id']) : null;

        $account->fill([
            'provider' => $state['provider'], 'sandbox' => (bool) ($state['sandbox'] ?? true), 'credentials' => $credentials,
            'settings' => array_merge($settings, [
                'sender_name' => trim((string) ($state['sender_name'] ?? '')), 'sender_phone' => trim((string) ($state['sender_phone'] ?? '')),
                'sender_address' => trim((string) ($state['sender_address'] ?? '')), 'origin_id' => $state['origin_id'] ?? null,
                'service' => trim((string) ($state['service'] ?? '')) ?: 'JNE REG', 'shipment_type' => ($state['shipment_type'] ?? 'PICKUP') === 'DROP' ? 'DROP' : 'PICKUP',
                'insurance' => (bool) ($state['insurance'] ?? false),
            ], $place ? ['origin_label' => CarrierLocation::fromArray($place)->label(), 'origin_postal' => $place['postalCode'] ?? null] : []),
        ]);

        if ($account->isDirty('sandbox') || $account->isDirty('credentials')) {
            $account->balance = null;
            $account->balance_at = null;
        }

        $account->save();

        Notification::make()->title('Pengiriman otomatis disimpan')->success()->send();
        $this->fillCarrier();
    }

    public function saveNotices(): void
    {
        $state = $this->noticesForm->getState();
        $account = $this->account();

        if ($account === null) {
            return;
        }

        $messages = [];

        foreach (StatusNotices::DEFAULTS as $status => $default) {
            $messages[$status] = array_filter([
                'enabled' => (bool) ($state[$status]['enabled'] ?? false),
                'text' => trim((string) ($state[$status]['text'] ?? '')) ?: $default['text'],
                'text_cod' => isset($default['text_cod']) ? (trim((string) ($state[$status]['text_cod'] ?? '')) ?: $default['text_cod']) : null,
            ], fn ($v) => $v !== null);
        }

        $account->forceFill(['messages' => $messages])->save();
        Notification::make()->title('Pesan status kiriman disimpan')->success()->send();
    }

    /** "Uji koneksi": a token and the balance from the provider. */
    public function testConnection(): void
    {
        $account = $this->account();

        if ($account === null || ! $account->hasCredentials()) {
            Notification::make()->title('Simpan penyedia, client key dan client secret dulu.')->warning()->send();

            return;
        }

        try {
            $balance = Carriers::make($account)->balance();
        } catch (CarrierException $e) {
            Notification::make()->title('Koneksi gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        $account->forceFill(['balance' => $balance, 'balance_at' => now()])->save();
        Notification::make()->title('Koneksi berhasil')->body('Saldo: '.($balance !== null ? 'Rp'.number_format($balance, 0, ',', '.') : 'tidak disebutkan'))->success()->send();
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
                        TagsInput::make('cities')->label('Kota/kabupaten yang ikut zona ini')->placeholder('Mis. Bekasi')->suggestions(fn () => RegionDirectory::cityNames())
                            ->helperText('Untuk zona yang lintas provinsi, mis. Bekasi dan Tangerang di Jabodetabek.'),
                        TagsInput::make('excluded_cities')->label('Kecuali kota/kabupaten')->placeholder('Mis. Bogor')->suggestions(fn () => RegionDirectory::cityNames()),
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
