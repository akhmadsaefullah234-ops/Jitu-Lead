<?php

namespace App\Filament\Pages;

use App\Ai\DocumentException;
use App\Ai\DocumentReader;
use App\Ai\Facts\FactExtractor;
use App\Ai\Facts\FactKeys;
use App\Ai\Facts\Facts;
use App\Ai\Facts\PaymentModel;
use App\Enums\Role;
use App\Filament\Concerns\InSettingsAndTools;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Isi properti": the main way a property agency tells its AI about the project. The wizard asks the project, each
 * unit type (price, areas, rooms, availability, bonus), payment, the optional rent estimate, survey hours and place,
 * and contact. Saving puts each type in the catalogue and writes the standard knowledge entries (App\Ai\Facts).
 * A document can be read into the same fields; what it says waits in "Tinjau data AI" for the owner.
 */
class PropertySetup extends Page
{
    use InSettingsAndTools;

    protected string $view = 'filament.pages.property-setup';

    protected static ?string $slug = 'isi-properti';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $navigationLabel = 'Isi properti';

    protected static ?string $title = 'Isi properti untuk AI';

    // Opened from "Pengaturan & Alat" (App\Filament\Support\SettingsCatalog), not from the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->roleOf(auth()->user()) === Role::Admin && $current->get()?->industry()->key === 'properti';
    }

    /** @var array<string, string> the free-text payment facts from before the payment model */
    public array $legacy = [];

    public function mount(): void
    {
        $form = Facts::form();
        $this->legacy = array_filter(array_intersect_key($form['project'], array_flip(['payment_methods', 'down_payment', 'tenor'])), 'filled');
        $this->form->fill($form);
    }

    public function form(Schema $schema): Schema
    {
        $text = fn (string $key, string $placeholder = '') => TextInput::make('project.'.$key)->label(FactKeys::label($key))->maxLength(500)->placeholder($placeholder);

        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make('Proyek')->description('Nama, lokasi, legalitas')->columns(2)->schema([
                    $text('project', 'Perumahan Contoh Asri')->required(),
                    $text('developer', 'PT Contoh Properti'),
                    $text('location', 'Jl. Contoh Raya No. 1, Denpasar Utara')->columnSpanFull(),
                    $text('legal', 'SHGB, bisa dipecah per unit'),
                    $text('handover', 'Sekitar 18 bulan setelah akad'),
                ]),
                Step::make('Tipe unit')->description('Harga, luas, kamar')->schema([
                    Repeater::make('types')->label('Tipe unit')->addActionLabel('Tambah tipe')->defaultItems(1)->columns(3)
                        ->itemLabel(fn (array $state) => filled($state['name'] ?? null) ? 'Tipe '.preg_replace('/^tipe\s+/iu', '', (string) $state['name']) : null)
                        ->schema([
                            Hidden::make('property_id'),
                            TextInput::make('name')->label('Nama tipe')->required()->maxLength(120)->placeholder('Melati'),
                            TextInput::make('price')->label('Harga')->prefix('Rp')->maxLength(40)->placeholder('642850000')
                                ->helperText('Angka lengkap, atau "642,85 juta".'),
                            Select::make('availability')->label('Ketersediaan')->options(FactKeys::AVAILABILITY),
                            TextInput::make('building_area')->label('Luas bangunan')->suffix('m2')->maxLength(20)->placeholder('38'),
                            TextInput::make('land_area')->label('Luas tanah')->suffix('m2')->maxLength(20)->placeholder('72'),
                            TextInput::make('bedrooms')->label('Kamar tidur')->maxLength(10)->placeholder('2'),
                            TextInput::make('bathrooms')->label('Kamar mandi')->maxLength(10)->placeholder('1'),
                            TextInput::make('bonus')->label('Bonus')->maxLength(300)->columnSpan(2)->placeholder('Kosongkan bila tidak ada'),
                        ]),
                ]),
                Step::make('Pembayaran')->description('Cara bayar, uang muka, tanda jadi')->columns(2)->schema([
                    CheckboxList::make('project.pay_options')->label('Cara bayar yang tersedia')->options(PaymentModel::OPTIONS)->columns(3)->live()->columnSpanFull()
                        ->helperText('AI hanya menawarkan yang dicentang. Yang tidak dicentang dijawab jujur "belum tersedia".'),
                    $text('installment_tenor', '24')->suffix('bulan')->visible(fn (Get $get) => in_array('installment', (array) $get('project.pay_options'), true)),
                    Select::make('project.installment_interest')->label(FactKeys::label('installment_interest'))->options(['tidak' => 'Tanpa bunga', 'ada' => 'Ada bunga'])
                        ->visible(fn (Get $get) => in_array('installment', (array) $get('project.pay_options'), true)),
                    $text('developer_tenor', '36')->suffix('bulan')->visible(fn (Get $get) => in_array('developer', (array) $get('project.pay_options'), true)),
                    $text('kpr_banks', 'Opsional, mis. Bank Contoh')->visible(fn (Get $get) => in_array('kpr', (array) $get('project.pay_options'), true)),
                    $text('pay_other', 'Mis. tukar tambah')->visible(fn (Get $get) => in_array('other', (array) $get('project.pay_options'), true)),
                    Select::make('project.dp_model')->label(FactKeys::label('dp_model'))->options(PaymentModel::DOWN_PAYMENTS)->live(),
                    $text('dp_amount', '5% atau Rp10.000.000')->visible(fn (Get $get) => in_array($get('project.dp_model'), ['light', 'standard'], true)),
                    $text('dp_promo', 'Mis. sampai 31 Desember')->visible(fn (Get $get) => $get('project.dp_model') === 'free'),
                    $text('booking_fee', '5000000'),
                    Select::make('project.booking_fee_reduces')->label(FactKeys::label('booking_fee_reduces'))->options(['ya' => 'Ya, mengurangi harga', 'tidak' => 'Tidak, di luar harga']),
                    $text('payment_notes', 'Opsional')->columnSpanFull(),
                    // Free text from before the payment model: shown only while it holds something, to be cleared once covered above.
                    ...array_map(fn (string $key) => $text($key)->columnSpanFull()->visible(fn () => filled($this->legacy[$key] ?? null))
                        ->helperText('Teks lama. Kosongkan bila sudah tercakup di isian di atas.'), ['payment_methods', 'down_payment', 'tenor']),
                    $text('rent_estimate', 'Kosongkan bila tidak ada')->helperText('Opsional. AI menyebutnya sebagai perkiraan, bukan janji.'),
                ]),
                Step::make('Survei')->description('Jam, lokasi, kontak')->columns(2)->schema([
                    $text('survey_hours', 'Setiap hari 09.00 sampai 17.00'),
                    $text('survey_location', 'Kantor pemasaran di lokasi proyek'),
                    Textarea::make('project.contact')->label(FactKeys::label('contact'))->rows(2)->maxLength(500)->columnSpanFull()
                        ->placeholder('Kantor pemasaran 0899000xxxx'),
                ]),
            ])->submitAction(new HtmlString(Blade::render('<x-filament::button type="submit">Simpan dan perbarui pengetahuan AI</x-filament::button>'))),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('readDocument')->label('Baca dari dokumen')->icon(Heroicon::OutlinedDocumentMagnifyingGlass)->color('gray')
                ->modalHeading('Baca data properti dari dokumen')
                ->modalDescription('Brosur, daftar harga, atau FAQ: PDF yang teksnya bisa disalin, Word (.docx), Excel (.xlsx), TXT, CSV, atau foto (JPG, PNG). AI menyalin datanya ke bidang di halaman ini, tetapi belum dipakai sebelum Anda setujui di "Tinjau data AI".')
                ->modalSubmitActionLabel('Baca')
                ->schema([
                    FileUpload::make('file')->label('File')->required()->storeFiles(false)->maxSize(DocumentReader::MAX_BYTES / 1024)
                        ->acceptedFileTypes([
                            'application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/csv', 'image/jpeg', 'image/png', 'image/webp',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->helperText('Maksimal 5 MB. Jangan unggah dokumen berisi data pribadi klien.'),
                ])
                ->action(function (array $data) {
                    $file = $data['file'];

                    if (! $file instanceof TemporaryUploadedFile) {
                        Notification::make()->title('File tidak terbaca, coba unggah lagi')->danger()->send();

                        return;
                    }

                    try {
                        $result = app(FactExtractor::class)($file->getRealPath(), $file->getClientOriginalName());
                    } catch (DocumentException $e) {
                        Notification::make()->title('Dokumen belum bisa dibaca')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title($result['added'].' data baru menunggu tinjauan')
                        ->body(($result['same'] > 0 ? $result['same'].' data sama dengan yang sudah ada. ' : '').'Setujui atau tolak di Tinjau data AI.')
                        ->actions([Action::make('review')->label('Tinjau data AI')->url(FactReview::getUrl(tenant: app(CurrentTenant::class)->get()))])
                        ->success()->send();
                }),
            Action::make('review')->label('Tinjau data AI')->icon(Heroicon::OutlinedClipboardDocumentCheck)->color('gray')->url(fn () => FactReview::getUrl(tenant: app(CurrentTenant::class)->get())),
        ];
    }

    public function save(): void
    {
        Facts::saveWizard($this->form->getState());
        $this->mount();

        Notification::make()->title('Data properti disimpan')
            ->body('Tipe unit masuk Katalog properti, dan pengetahuan AI standar diperbarui.')->success()->send();
    }
}
