<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Concerns\InSettingsAndTools;
use App\Models\PaymentGateway;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan > Pembayaran otomatis, for digital-product agencies only: the agency's own iPaymu account. With it on,
 * a confirmed purchase gets a QRIS / virtual account payment link from the system and is marked paid when iPaymu
 * says so (App\Payments\DigitalCheckout). The VA and API key are stored encrypted and never shown back: an empty box
 * keeps the saved key.
 */
class PaymentGatewayPage extends Page
{
    use InSettingsAndTools;

    protected string $view = 'filament.pages.payment-gateway';

    protected static ?string $slug = 'pembayaran-otomatis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Pembayaran otomatis';

    protected static ?string $title = 'Pembayaran otomatis (iPaymu)';

    // Opened from "Pengaturan & Alat" (App\Filament\Support\SettingsCatalog), not from the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 15;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->roleOf(auth()->user()) === Role::Admin && $current->get()?->industry()->key === 'digital';
    }

    private function gateway(): ?PaymentGateway
    {
        return PaymentGateway::query()->first();
    }

    public function mount(): void
    {
        $g = $this->gateway();

        $this->form->fill([
            'active' => (bool) $g?->active, 'sandbox' => $g?->sandbox ?? true, 'va' => null, 'api_key' => null,
            'invoice_hours' => $g?->invoice_hours ?? 24,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $saved = fn () => filled($this->gateway()?->va) && filled($this->gateway()?->api_key);

        return $schema->statePath('data')->components([
            Section::make('Akun iPaymu agensi')
                ->description('Uang pembeli masuk ke akun iPaymu Anda sendiri. Ambil VA dan API Key di dasbor iPaymu (Pengaturan > API). Mulailah dengan mode sandbox untuk mencoba.')
                ->columns(2)
                ->schema([
                    TextInput::make('va')->label('VA iPaymu')->password()->autocomplete('off')->maxLength(60)
                        ->placeholder(fn () => $saved() ? 'Tersimpan. Kosongkan untuk tetap memakai yang lama.' : '1179000899xxxxxx'),
                    TextInput::make('api_key')->label('API Key')->password()->autocomplete('off')->maxLength(200)
                        ->placeholder(fn () => $saved() ? 'Tersimpan. Kosongkan untuk tetap memakai yang lama.' : 'SANDBOX...'),
                    Toggle::make('sandbox')->label('Mode sandbox (uji coba, tanpa uang sungguhan)'),
                    Toggle::make('active')->label('Aktifkan pembayaran otomatis'),
                    Text::make(fn () => 'URL notifikasi (callback) untuk dasbor iPaymu: '.route('digital.ipaymu.notify', app(CurrentTenant::class)->id()))->columnSpanFull(),
                ]),
            Section::make('Invoice dan akses')->columns(2)->schema([
                TextInput::make('invoice_hours')->label('Tautan bayar berlaku')->numeric()->integer()->minValue(1)->maxValue(168)->suffix('jam')->required()
                    ->helperText('Pengingat dikirim sekali, 3 jam sebelum kedaluwarsa.'),
                Text::make('Masa berlaku dan batas unduhan tautan akses pembeli diatur per produk di Katalog (bawaan 90 hari, 10 kali unduh).'),
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
        $gateway = $this->gateway() ?? new PaymentGateway(['provider' => 'ipaymu']);
        $va = trim((string) ($state['va'] ?? ''));
        $key = trim((string) ($state['api_key'] ?? ''));

        $gateway->fill(array_filter(['va' => $va, 'api_key' => $key], fn ($v) => $v !== '') + [
            'sandbox' => (bool) $state['sandbox'],
            'active' => (bool) $state['active'],
            'invoice_hours' => (int) $state['invoice_hours'],
        ]);

        if ($gateway->active && (blank($gateway->va) || blank($gateway->api_key))) {
            throw ValidationException::withMessages(['data.api_key' => 'Isi VA dan API Key iPaymu sebelum mengaktifkan pembayaran otomatis.']);
        }

        $gateway->save();
        $this->mount();

        Notification::make()->title('Pembayaran otomatis disimpan')->body($gateway->active ? 'Pembelian yang dikonfirmasi mendapat tautan bayar QRIS/VA dari sistem.' : 'Pembayaran otomatis mati.')->success()->send();
    }
}
