<?php

namespace App\Filament\Pages;

use App\Ai\DocumentException;
use App\Ai\DocumentReader;
use App\Ai\Facts\FactExtractor;
use App\Ai\Facts\FactKeys;
use App\Ai\Facts\Facts;
use App\Enums\Role;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
    protected string $view = 'filament.pages.property-setup';

    protected static ?string $slug = 'isi-properti';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $navigationLabel = 'Isi properti';

    protected static ?string $title = 'Isi properti untuk AI';

    protected static string|\UnitEnum|null $navigationGroup = 'AI Asisten';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $current = app(CurrentTenant::class);

        return $current->roleOf(auth()->user()) === Role::Admin && $current->get()?->industry()->key === 'properti';
    }

    public function mount(): void
    {
        $this->form->fill(Facts::form());
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
                Step::make('Pembayaran')->description('Cara bayar, tenor, sewa')->columns(2)->schema([
                    $text('payment_methods', 'Cash keras, cash bertahap 12 kali, KPR')->columnSpanFull(),
                    $text('booking_fee', '5000000'),
                    $text('down_payment', '10% dari harga'),
                    $text('tenor', 'KPR sampai 20 tahun'),
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
