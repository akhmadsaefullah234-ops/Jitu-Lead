<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Concerns\InSettingsAndTools;
use App\Models\PaymentAccount;
use App\Payments\PaymentInstructions;
use App\Payments\PaymentSettings;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan > Pembayaran, untuk semua industri: rekening bank tenant, kode unik, batas waktu bayar, dan template
 * instruksi bayar transfer, dengan pratinjau pesannya. Begitu ada rekening aktif, pesanan transfer yang dicatat
 * (App\Actions\RecordOrder) mendapat instruksi bayar dari sistem (App\Payments\PaymentInstructions). Tanpa rekening
 * aktif, tidak ada yang berubah.
 */
class PaymentSettingsPage extends Page
{
    use InSettingsAndTools;

    protected string $view = 'filament.pages.payment-settings';

    protected static ?string $slug = 'pembayaran';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Pembayaran';

    protected static ?string $title = 'Pembayaran';

    // Opened from "Pengaturan & Alat" (App\Filament\Support\SettingsCatalog), not from the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 14;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    public function mount(): void
    {
        $tenant = app(CurrentTenant::class)->get();

        $this->form->fill(PaymentSettings::of($tenant)->toArray() + [
            'accounts' => $tenant->paymentAccounts()->get()
                ->map(fn (PaymentAccount $a) => ['id' => $a->getKey(), 'bank_name' => $a->bank_name, 'account_number' => $a->account_number, 'account_holder' => $a->account_holder, 'active' => $a->active])
                ->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Rekening bank')
                ->description('Urutkan dengan menyeret. Instruksi bayar memakai rekening aktif teratas, atau semua rekening aktif bila diatur di bawah. '
                    .'Selama belum ada rekening aktif, AI tetap memakai rekening dari Pengetahuan seperti sebelumnya.')
                ->schema([
                    Repeater::make('accounts')->hiddenLabel()->addActionLabel('Tambah rekening')->reorderable()->defaultItems(0)->live()
                        ->itemLabel(fn (array $state) => trim(($state['bank_name'] ?? '').' '.($state['account_number'] ?? '')) ?: 'Rekening baru')
                        ->columns(2)
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('bank_name')->label('Bank')->required()->maxLength(60)->placeholder('BCA')->live(onBlur: true),
                            TextInput::make('account_number')->label('Nomor rekening')->required()->maxLength(40)->placeholder('1234567890')->live(onBlur: true)
                                ->regex('/^[0-9][0-9 \-]*$/')->validationMessages(['regex' => 'Nomor rekening hanya angka, spasi, atau tanda hubung.']),
                            TextInput::make('account_holder')->label('Atas nama')->required()->maxLength(120)->live(onBlur: true),
                            Toggle::make('active')->label('Aktif')->default(true)->inline(false)->live(),
                        ]),
                    Toggle::make('semua_rekening')->label('Tulis semua rekening aktif di instruksi')->live()
                        ->helperText('Bila mati, hanya rekening aktif teratas yang dikirim ke pembeli.'),
                ]),
            Section::make('Instruksi bayar')->columns(2)->schema([
                Toggle::make('kode_unik')->label('Tambahkan kode unik 3 digit')->live()
                    ->helperText('Sistem menambah 100 sampai 999 rupiah ke total, menyimpannya di lead, dan memakai kode yang sama bila pesanan dikonfirmasi ulang. Nilai pesanan (dan event Purchase) tetap total tanpa kode unik.'),
                TextInput::make('batas_waktu_bayar')->label('Batas waktu bayar')->numeric()->integer()->minValue(1)->maxValue(PaymentSettings::MAX_DEADLINE_HOURS)->suffix('jam')->live(onBlur: true)
                    ->helperText('Kosongkan bila tidak ada batas. Dihitung sejak pesanan dicatat.'),
                Textarea::make('template_instruksi_bayar')->label('Template pesan')->rows(9)->maxLength(2000)->required()->live(onBlur: true)->columnSpanFull()
                    ->helperText('Variabel: {total} {rekening} {kode_unik} {batas_bayar} {nama}. {total} dan {rekening} wajib ada. Baris yang memuat {kode_unik} atau {batas_bayar} dilewati bila kode unik mati atau batas waktu kosong.'),
                Text::make(fn (Get $get) => new HtmlString('<div style="font-weight:600;margin-bottom:.25rem">Pratinjau pesan (data contoh)</div>'
                    .'<div style="white-space:pre-wrap;border:1px solid var(--gray-200);border-radius:.5rem;padding:.75rem;background:var(--gray-50);color:var(--gray-900);max-width:32rem" data-preview>'
                    .e(PaymentInstructions::preview(app(CurrentTenant::class)->get(), (string) $get('template_instruksi_bayar'), array_values((array) $get('accounts')),
                        (bool) $get('kode_unik'), filled($get('batas_waktu_bayar')) ? (int) $get('batas_waktu_bayar') : null)).'</div>'))
                    ->columnSpanFull()->key('preview'),
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
        $template = trim((string) ($state['template_instruksi_bayar'] ?? ''));

        if (($missing = PaymentSettings::missingVariables($template)) !== []) {
            throw ValidationException::withMessages(['data.template_instruksi_bayar' => 'Template wajib memuat '.implode(' dan ', $missing).'.']);
        }

        DB::transaction(function () use ($state, $tenant, $template) {
            $tenant->forceFill(['payment_settings' => [
                'batas_waktu_bayar' => filled($state['batas_waktu_bayar'] ?? null) ? (int) $state['batas_waktu_bayar'] : null,
                'kode_unik' => (bool) ($state['kode_unik'] ?? false),
                'semua_rekening' => (bool) ($state['semua_rekening'] ?? false),
                'template_instruksi_bayar' => $template,
            ]])->save();

            // Rekening disimpan lewat relasi tenant: id dari form hanya dipakai bila milik tenant ini.
            $kept = [];

            foreach (array_values($state['accounts'] ?? []) as $i => $row) {
                $values = [
                    'bank_name' => trim((string) $row['bank_name']),
                    'account_number' => trim((string) $row['account_number']),
                    'account_holder' => trim((string) $row['account_holder']),
                    'active' => (bool) ($row['active'] ?? true),
                    'sort_order' => $i,
                ];
                $account = filled($row['id'] ?? null) ? $tenant->paymentAccounts()->whereKey((int) $row['id'])->first() : null;

                if ($account !== null) {
                    $account->fill($values)->save();
                } else {
                    $account = $tenant->paymentAccounts()->create($values);
                }

                $kept[] = $account->getKey();
            }

            $tenant->paymentAccounts()->whereNotIn('id', $kept)->delete();
        });

        $this->mount();

        Notification::make()->title('Pengaturan pembayaran disimpan')->success()->send();
    }
}
