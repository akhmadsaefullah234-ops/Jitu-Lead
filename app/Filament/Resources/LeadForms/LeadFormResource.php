<?php

namespace App\Filament\Resources\LeadForms;

use App\Billing\PlanLimits;
use App\Filament\Resources\LeadForms\Pages\CreateLeadForm;
use App\Filament\Resources\LeadForms\Pages\EditLeadForm;
use App\Filament\Resources\LeadForms\Pages\ListLeadForms;
use App\LandingPages\Buttons;
use App\LandingPages\Sections;
use App\LeadForms\FormFields;
use App\LeadForms\FormTemplates;
use App\Models\LandingPage;
use App\Models\LeadForm;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Tracking\PixelEvents;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * "Formulir": many lead forms per agency, each with its own fields, look, after-sending step, buttons and pixels,
 * edited beside a live preview. The agency's main form is also what the older /f/{token} link shows.
 */
class LeadFormResource extends Resource
{
    protected static ?string $model = LeadForm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'formulir';

    protected static ?string $pluralModelLabel = 'formulir';

    protected static ?string $navigationLabel = 'Formulir';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'name';

    private static function nameField(): TextInput
    {
        return TextInput::make('name')->label('Nama formulir')->required()->maxLength(120)->live(onBlur: true)
            ->helperText('Untuk Anda sendiri, misalnya "Iklan Ramadan" atau "Pemesanan kaos".')
            ->afterStateUpdated(fn ($state, Set $set, Get $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null);
    }

    private static function slugField(): TextInput
    {
        return TextInput::make('slug')->label('Alamat formulir')->required()->maxLength(80)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
            ->prefix(fn () => '/f/'.app(CurrentTenant::class)->get()?->slug.'/')
            ->helperText('Huruf kecil, angka, dan tanda hubung.')
            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', app(CurrentTenant::class)->id()));
    }

    public static function form(Schema $schema): Schema
    {
        $isAfter = fn (string $type) => fn (Get $get) => $get('after_submit.type') === $type;

        return $schema->components([
            Section::make('Buat formulir baru')->columnSpanFull()->visibleOn('create')
                ->description('Pilih titik awal. Semua kolom, tampilan, dan aksi setelah kirim bisa diubah setelahnya.')
                ->schema([
                    Radio::make('template')->label('Mulai dari')->required()->default(FormTemplates::BLANK)
                        ->options(fn () => array_map(fn ($t) => $t['name'], FormTemplates::options(Industry::current()->key)))
                        ->descriptions(fn () => array_map(fn ($t) => $t['description'], FormTemplates::options(Industry::current()->key))),
                    self::nameField(),
                    self::slugField(),
                ]),

            Grid::make(['default' => 1, 'xl' => 2])->columnSpanFull()->visibleOn('edit')->schema([
                Group::make([
                    Section::make('Pengaturan')->columns(['default' => 1, 'sm' => 2])->schema([
                        self::nameField(),
                        self::slugField(),
                        Select::make('status')->label('Status')->options(['active' => 'Aktif (bisa diisi)', 'inactive' => 'Nonaktif'])->default('active')->required()->native(false),
                        Select::make('property_id')->label(ucfirst(Industry::t('catalog_item')).' formulir ini (opsional)')->columnSpanFull()
                            ->relationship('property', 'name')->searchable()->preload()->placeholder('Tidak ada / dari jawaban pembeli')
                            ->helperText('Lead dari formulir ini langsung tercatat menanyakan '.Industry::t('catalog_item').' ini, sehingga AI dan follow-up '.Industry::t('catalog_item').' itu yang dipakai. Bila formulir punya kolom "'.FormFields::MAPS['product'].'", jawaban pembeli yang cocok dengan nama '.Industry::t('catalog_item').' didahulukan.'),
                    ]),
                    Section::make('Tampilan')->collapsible()->columns(['default' => 1, 'sm' => 2])->schema([
                        TextInput::make('design.title')->label('Judul')->required()->maxLength(120)->columnSpanFull(),
                        Textarea::make('design.description')->label('Deskripsi')->rows(3)->maxLength(600)->columnSpanFull(),
                        Sections::upload('design.banner')->label('Gambar / banner atas (opsional)')->columnSpanFull(),
                        ColorPicker::make('design.color')->label('Warna utama')->default('#dc2626')->required()->regex(LandingPage::COLOR_PATTERN),
                        Select::make('design.font')->label('Jenis huruf')->options(array_map(fn ($f) => $f[0], LandingPage::FONTS))->default('modern')->native(false),
                        Select::make('design.background')->label('Latar')->options(LeadForm::BACKGROUNDS)->default('soft')->native(false),
                        Sections::upload('design.background_image')->label('Foto latar (opsional)'),
                        TextInput::make('design.privacy')->label('Catatan privasi di bawah tombol (opsional)')->maxLength(250)->columnSpanFull(),
                    ]),
                    Section::make('Kolom formulir')->collapsible()
                        ->description('Tambah, seret, atau pakai tombol naik/turun untuk mengurutkan. Setiap kolom bisa disimpan ke kolom lead.')
                        ->schema([FormFields::repeater()->hiddenLabel()]),
                    Section::make('Tombol kirim')->collapsible()->collapsed()->columns(['default' => 1, 'sm' => 2])->schema([
                        TextInput::make('submit.label')->label('Teks tombol')->required()->maxLength(40)->default('Kirim'),
                        Select::make('submit.color')->label('Warna')->options(Buttons::COLORS)->default('brand')->native(false),
                        Select::make('submit.size')->label('Ukuran')->options(Buttons::SIZES)->default('lg')->native(false),
                        Select::make('submit.icon')->label('Ikon')->options(array_map(fn ($i) => $i[0], Buttons::ICONS))->default('none')->native(false),
                        PixelEvents::fieldset('submit.pixel', 'Pixel saat formulir terkirim', 'event formulir')->columnSpanFull(),
                    ]),
                    Section::make('Setelah formulir dikirim')->collapsible()->collapsed()
                        ->description('Lead selalu tersimpan lebih dulu, apa pun pilihannya.')
                        ->schema([
                            Radio::make('after_submit.type')->label('Aksi')->options(LeadForm::AFTER)->default('message')->required()->live(),
                            Textarea::make('after_submit.message')->label('Pesan terima kasih')->rows(2)->maxLength(300)
                                ->helperText('Tampil setelah kirim, juga sesaat sebelum pindah halaman.'),
                            Grid::make(['default' => 1, 'sm' => 2])->visible($isAfter('redirect'))->schema([
                                Select::make('after_submit.page_kind')->label('Halaman terima kasih')->options(['landing' => 'Halaman landing milik agensi', 'url' => 'Alamat (URL) bebas'])->default('landing')->native(false)->live(),
                                Select::make('after_submit.landing_page_id')->label('Halaman landing')->options(fn () => LandingPage::query()->orderBy('title')->pluck('title', 'id'))->searchable()
                                    ->visible(fn (Get $get) => $get('after_submit.page_kind') !== 'url')->required(fn (Get $get) => $get('after_submit.type') === 'redirect' && $get('after_submit.page_kind') !== 'url'),
                                TextInput::make('after_submit.url')->label('URL')->url()->maxLength(500)->placeholder('https://...')
                                    ->visible(fn (Get $get) => $get('after_submit.page_kind') === 'url')->required(fn (Get $get) => $get('after_submit.type') === 'redirect' && $get('after_submit.page_kind') === 'url'),
                                Toggle::make('after_submit.new_tab')->label('Buka di tab baru'),
                            ]),
                            Grid::make(['default' => 1, 'sm' => 2])->visible($isAfter('whatsapp'))->schema([
                                Select::make('after_submit.wa_target')->label('Nomor tujuan')->options([
                                    'agency' => 'Nomor WhatsApp agensi', 'rotate' => 'Agen yang menerima lead ini (bergiliran)', 'manual' => 'Nomor yang saya isi',
                                ])->default('rotate')->native(false)->live(),
                                TextInput::make('after_submit.wa_number')->label('Nomor WhatsApp')->tel()->maxLength(20)
                                    ->visible(fn (Get $get) => $get('after_submit.wa_target') === 'manual')->required(fn (Get $get) => $get('after_submit.type') === 'whatsapp' && $get('after_submit.wa_target') === 'manual'),
                                Textarea::make('after_submit.wa_message')->label('Pesan otomatis')->rows(4)->maxLength(1000)->columnSpanFull()
                                    ->helperText(fn (Get $get) => 'Variabel: '.collect(FormFields::normalize($get('fields')))->where('type', '!=', 'photo')->map(fn ($f) => '{'.$f['key'].'}')->implode(', ')
                                        .', serta {nama}, {telepon}, {email}, {alamat}, {jumlah}, {varian}, {judul}.'),
                            ]),
                        ]),
                    Section::make('Tombol tambahan')->collapsible()->collapsed()
                        ->description('Tampil di bawah tombol kirim, misalnya "Chat langsung via WhatsApp".')
                        ->schema([Buttons::repeater('buttons', 'Tombol', [], 3)->hiddenLabel()]),
                    Section::make('Pixel saat formulir dibuka')->collapsible()->collapsed()
                        ->visible(fn () => PlanLimits::current()?->feature('pixels') ?? true)
                        ->schema([PixelEvents::fieldset('page_events', 'Event', null)]),
                    Section::make('Bagikan dan sematkan')->collapsible()->collapsed()
                        ->schema([View::make('filament.lead-form-share')]),
                ])->columnSpan(1),
                Group::make([View::make('filament.landing-live-preview')])->columnSpan(1),
            ]),
        ]);
    }

    public static function previewUrl(LeadForm $form): string
    {
        return URL::temporarySignedRoute('form.preview', now()->addMinutes(30), [$form->tenant->slug, $form->slug]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('name')->label('Formulir')->weight('bold')->searchable()
                ->description(fn (LeadForm $r) => '/f/'.$r->tenant->slug.'/'.$r->slug.($r->is_primary ? ' · formulir utama' : '')),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => $state === 'inactive' ? 'Nonaktif' : 'Aktif')
                ->color(fn (string $state) => $state === 'inactive' ? 'gray' : 'success'),
            TextColumn::make('submissions')->label('Terkirim')->numeric()->sortable(),
            TextColumn::make('views')->label('Dilihat')->numeric()->sortable(),
            TextColumn::make('updated_at')->label('Diubah')->since()->sortable(),
        ])->recordActions([
            Action::make('open')->label('Buka')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn (LeadForm $r) => $r->isActive() ? $r->publicUrl() : static::previewUrl($r), shouldOpenInNewTab: true),
            Action::make('share')->label('Sematkan')->icon(Heroicon::OutlinedCodeBracket)->color('gray')
                ->modalHeading('Bagikan dan sematkan')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                ->modalContent(fn (LeadForm $record) => view('filament.lead-form-share', ['record' => $record])),
            Action::make('duplicate')->label('Duplikat')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->visible(fn () => static::canCreate())
                ->action(function (LeadForm $record) {
                    $copy = static::duplicate($record);
                    Notification::make()->success()->title('Formulir diduplikat')->send();

                    return redirect(static::getUrl('edit', ['record' => $copy]));
                }),
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function duplicate(LeadForm $form): LeadForm
    {
        $base = Str::limit($form->slug, 70, '').'-salinan';
        $slug = $base;

        for ($i = 2; LeadForm::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        $copy = $form->replicate(['views', 'submissions', 'is_primary']);
        $copy->forceFill(['name' => Str::limit($form->name, 100, '').' (salinan)', 'slug' => $slug, 'is_primary' => false, 'views' => 0, 'submissions' => 0])->save();

        return $copy;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadForms::route('/'),
            'create' => CreateLeadForm::route('/create'),
            'edit' => EditLeadForm::route('/{record}/edit'),
        ];
    }
}
