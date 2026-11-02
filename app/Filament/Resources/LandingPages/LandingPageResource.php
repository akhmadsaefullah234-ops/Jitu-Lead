<?php

namespace App\Filament\Resources\LandingPages;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\Filament\Resources\LandingPages\Pages\ListLandingPages;
use App\LandingPages\BlockStyle;
use App\LandingPages\Navigation;
use App\LandingPages\PageLayout;
use App\LandingPages\Sections;
use App\LandingPages\Templates;
use App\Models\LandingPage;
use App\Models\LeadForm;
use App\Models\Property;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Tracking\PixelEvents;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class LandingPageResource extends Resource
{
    protected static ?string $model = LandingPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $modelLabel = 'halaman landing';

    protected static ?string $pluralModelLabel = 'halaman landing';

    protected static ?string $navigationLabel = 'Halaman landing';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'title';

    private static function titleField(): TextInput
    {
        return TextInput::make('title')->label('Judul halaman')->required()->maxLength(120)->live(onBlur: true)
            ->afterStateUpdated(fn ($state, callable $set, callable $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null);
    }

    private static function slugField(): TextInput
    {
        return TextInput::make('slug')->label('Alamat halaman')->required()->maxLength(80)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
            ->helperText('Huruf kecil, angka, dan tanda hubung. Menjadi bagian dari alamat halaman.')
            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', app(CurrentTenant::class)->id()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // New page: pick a starting point; the editor opens right after.
            Section::make('Panduan: membuat halaman dalam 10 menit')->collapsible()->collapsed(fn (string $operation) => $operation === 'edit')->columnSpanFull()
                ->schema([View::make('filament.guides.landing')]),
            Section::make('Buat halaman baru')->columnSpanFull()->visibleOn('create')
                ->description('Pilih template. Isinya contoh yang tinggal Anda ganti, jadi halaman pertama bisa selesai dalam sekitar 10 menit.')
                ->schema([
                    Radio::make('template')->label('Mulai dari')->required()->default(Templates::BLANK)->live()
                        ->options(fn () => array_map(fn ($t) => $t['name'], Templates::options(Industry::current()->key)))
                        ->descriptions(fn () => array_map(fn ($t) => $t['description'], Templates::options(Industry::current()->key))),
                    Select::make('product_id')->label('Ambil data produk dari katalog')->placeholder('Isi manual di editor')->native(false)->searchable()
                        ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Get $get) => Templates::sellsProduct($get('template')))
                        ->helperText('Nama, harga, varian, ukuran, dan rincian produk diisi otomatis, jadi tidak perlu diketik ulang. Foto diunggah di editor.'),
                    Select::make('order_form')->label('Formulir order')->native(false)->default('new')->selectablePlaceholder(false)
                        ->options(fn () => ['new' => 'Buat formulir baru dari template "Order produk"'] + LeadForm::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get) => Templates::sellsProduct($get('template')))
                        ->helperText('Satu formulir order per produk memudahkan pilihan varian dan ringkasan WhatsApp-nya.'),
                    self::titleField(),
                    self::slugField(),
                ]),

            // Editing: settings and sections on the left, live preview on the right.
            Grid::make(['default' => 1, 'xl' => 2])->columnSpanFull()->visibleOn('edit')->schema([
                Tabs::make('editor')->columnSpan(1)->persistTabInQueryString()->tabs([
                    Tab::make('Isi halaman')->icon(Heroicon::OutlinedSquares2x2)->schema([
                        Section::make('Isi halaman')->description('Tambah, seret, atau pakai tombol naik/turun untuk mengurutkan. Gunakan ikon salin untuk menggandakan bagian.')->schema([
                            Builder::make('blocks')->hiddenLabel()->addActionLabel('Tambah bagian')
                                ->blocks(fn (?LandingPage $record) => Sections::blocks(Industry::current()->key, array_column(array_filter((array) ($record?->blocks ?? []), 'is_array'), 'type')))
                                ->collapsible()->collapsed()->cloneable()->reorderable()->reorderableWithButtons()->blockNumbers(false)->blockPickerColumns(2)
                                ->extraItemActions(self::styleActions()),
                        ]),
                    ]),
                    Tab::make('Tombol & navigasi')->icon(Heroicon::OutlinedCursorArrowRays)->schema(Navigation::schema()),
                    Tab::make('Pengaturan')->icon(Heroicon::OutlinedCog6Tooth)->schema([
                        Section::make('Pengaturan halaman')->columns(2)->schema([
                            self::titleField(),
                            self::slugField(),
                            Select::make('status')->label('Status')->options(['draft' => 'Draf (belum tampil)', 'published' => 'Terbit (tampil untuk umum)'])->default('draft')->required()->native(false),
                            TextInput::make('whatsapp_number')->label('Nomor WhatsApp tujuan')->tel()->maxLength(20)->placeholder('08123456789')
                                ->helperText('Kosongkan untuk memakai nomor WhatsApp agensi.'),
                            Select::make('property_id')->label(Industry::t('catalog_item').' yang dipromosikan')->placeholder('Tidak dipilih')
                                ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id'))->searchable()
                                ->helperText('Lead dari halaman ini otomatis tercatat tertarik pada '.Industry::t('catalog_item').' ini.'),
                        ]),
                    ]),
                    Tab::make('Tampilan')->icon(Heroicon::OutlinedSwatch)->schema([
                        Section::make('Warna dan huruf')->columns(2)->schema([
                            Select::make('font')->label('Jenis huruf')->options(array_map(fn ($f) => $f[0], LandingPage::FONTS))->default('modern')->required()->native(false),
                            ColorPicker::make('color')->label('Warna utama')->default('#dc2626')->required()->regex(LandingPage::COLOR_PATTERN),
                            Sections::upload('logo', 600)->label('Logo (opsional)'),
                        ]),
                        PageLayout::section(),
                    ]),
                    Tab::make('Pixel & SEO')->icon(Heroicon::OutlinedChartBar)->schema([
                        Section::make('Pixel saat halaman dibuka')
                            ->description('Untuk halaman terima kasih, misalnya Lead, CompleteRegistration, atau Purchase. Biarkan "Tidak ada" untuk halaman biasa; PageView tetap terkirim otomatis.')
                            ->visible(fn () => PlanLimits::current()?->feature('pixels') ?? true)
                            ->schema([PixelEvents::fieldset('page_events', 'Event', null)]),
                        Section::make('SEO dan berbagi')->columns(1)->description('Tampilan saat halaman muncul di Google atau dibagikan lewat WhatsApp dan Facebook.')->schema([
                            TextInput::make('meta_title')->label('Judul untuk Google dan pratinjau link')->maxLength(120)->helperText('Kosongkan untuk memakai judul halaman.'),
                            Textarea::make('description')->label('Deskripsi singkat')->rows(2)->maxLength(300),
                            Sections::upload('og_image')->label('Gambar pratinjau link (disarankan 1200 x 630)')
                                ->helperText('Kosongkan untuk memakai foto latar bagian utama.'),
                        ]),
                    ]),
                ]),
                Group::make([View::make('filament.landing-live-preview')])->columnSpan(1),
            ]),
        ]);
    }

    public const STYLE_CLIPBOARD = 'landing.style_clipboard';

    /** "Hapus jarak atas/bawah", "Salin gaya", "Tempel gaya" and "Gaya bawaan" on every section: the copied style is kept for this login, so it also pastes onto other pages. */
    private static function styleActions(): array
    {
        $setStyle = function (Builder $component, string $item, array $style): void {
            $state = $component->getRawState();

            if (! isset($state[$item])) {
                return;
            }

            if (isset($style['bg_image'])) {
                $style['bg_image'] = [(string) Str::uuid() => $style['bg_image']];
            }

            $state[$item]['data']['style'] = $style;
            $component->rawState($state);
        };

        // "Hapus jarak atas/bawah": no padding on that side (PC and HP) and no margin, e.g. so the next section sits right under the hero.
        $clearSpace = function (Builder $component, string $item, string $side): void {
            $state = $component->getRawState();

            if (! isset($state[$item])) {
                return;
            }

            $style = (array) ($state[$item]['data']['style'] ?? []);
            $style['pad_'.$side] = 0;
            $style['margin_'.$side] = null;
            $style['mobile'] = array_merge((array) ($style['mobile'] ?? []), ['pad_'.$side => 0, 'margin_'.$side => null]);
            $state[$item]['data']['style'] = $style;
            $component->rawState($state);
        };

        return [
            Action::make('clearTop')->label('Hapus jarak atas')->tooltip('Hapus jarak atas')->icon(Heroicon::OutlinedBarsArrowUp)->color('gray')->iconButton()
                ->action(fn (array $arguments, Builder $component) => $clearSpace($component, $arguments['item'], 'top')),
            Action::make('clearBottom')->label('Hapus jarak bawah')->tooltip('Hapus jarak bawah')->icon(Heroicon::OutlinedBarsArrowDown)->color('gray')->iconButton()
                ->action(fn (array $arguments, Builder $component) => $clearSpace($component, $arguments['item'], 'bottom')),
            Action::make('copyStyle')->label('Salin gaya')->tooltip('Salin gaya')->icon(Heroicon::OutlinedClipboardDocument)->color('gray')->iconButton()
                ->action(function (array $arguments, Builder $component) {
                    session([self::STYLE_CLIPBOARD => BlockStyle::clean($component->getRawState()[$arguments['item']]['data']['style'] ?? [])]);
                    Notification::make()->success()->title('Gaya disalin. Tempel di bagian lain dengan ikon tempel gaya.')->send();
                }),
            Action::make('pasteStyle')->label('Tempel gaya')->tooltip('Tempel gaya')->icon(Heroicon::OutlinedClipboardDocumentCheck)->color('gray')->iconButton()
                ->action(function (array $arguments, Builder $component) use ($setStyle) {
                    $clip = session(self::STYLE_CLIPBOARD);

                    if (! is_array($clip)) {
                        Notification::make()->warning()->title('Belum ada gaya yang disalin.')->send();

                        return;
                    }

                    $setStyle($component, $arguments['item'], $clip);
                }),
            Action::make('resetStyle')->label('Kembalikan gaya bawaan')->tooltip('Kembalikan gaya bawaan')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->iconButton()
                ->requiresConfirmation()->modalDescription('Semua pengaturan Gaya bagian ini dikosongkan.')
                ->action(fn (array $arguments, Builder $component) => $setStyle($component, $arguments['item'], [])),
        ];
    }

    public static function previewUrl(LandingPage $page): string
    {
        return URL::temporarySignedRoute('landing.preview', now()->addMinutes(30), [$page->tenant->slug, $page->slug]);
    }

    /**
     * A draft copy of a page. Counts against the plan like any new page, so
     * the caller gets null (and the reason shown) when the plan is full.
     */
    public static function duplicate(LandingPage $page): ?LandingPage
    {
        if ($denied = PlanLimits::current()?->denyAdding('landing_pages')) {
            Deny::notify($denied);

            return null;
        }

        $base = Str::limit($page->slug, 70, '').'-salinan';
        $slug = $base;

        for ($i = 2; LandingPage::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        $copy = $page->replicate(['views', 'published_at']);
        $copy->forceFill(['title' => Str::limit($page->title, 100, '').' (salinan)', 'slug' => $slug, 'status' => 'draft', 'views' => 0, 'published_at' => null])->save();

        return $copy;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('title')->label('Halaman')->weight('bold')->searchable()->description(fn (LandingPage $r) => '/p/'.$r->tenant->slug.'/'.$r->slug),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => $state === 'published' ? 'Terbit' : 'Draf')
                ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
            TextColumn::make('views')->label('Dilihat')->numeric()->sortable(),
            TextColumn::make('updated_at')->label('Diubah')->since()->sortable(),
        ])->recordActions([
            Action::make('open')->label('Buka')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn (LandingPage $r) => $r->isPublished() ? $r->publicUrl() : static::previewUrl($r), shouldOpenInNewTab: true),
            Action::make('duplicate')->label('Duplikat')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->visible(fn () => static::canCreate())
                ->action(function (LandingPage $record) {
                    if ($copy = static::duplicate($record)) {
                        Notification::make()->success()->title('Halaman diduplikat sebagai draf')->send();

                        return redirect(static::getUrl('edit', ['record' => $copy]));
                    }
                }),
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLandingPages::route('/'),
            'create' => CreateLandingPage::route('/create'),
            'edit' => EditLandingPage::route('/{record}/edit'),
        ];
    }
}
