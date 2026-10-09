<?php

namespace App\Filament\Resources\LandingPages;

use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\Filament\Resources\LandingPages\Pages\ListLandingPages;
use App\Models\LandingPage;
use App\Models\Property;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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

    public static function form(Schema $schema): Schema
    {
        $upload = fn (string $name) => FileUpload::make($name)->image()->disk('public')
            ->directory(fn () => 'landing/'.app(CurrentTenant::class)->id())->visibility('public')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)->imageResizeMode('contain')
            ->imageResizeTargetWidth(1600)->imageResizeTargetHeight(1600)->imageResizeUpscale(false);

        return $schema->components([
            Section::make('Halaman')->columns(2)->columnSpanFull()->schema([
                TextInput::make('title')->label('Judul halaman')->required()->maxLength(120)->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, callable $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null),
                TextInput::make('slug')->label('Alamat halaman')->required()->maxLength(80)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->helperText('Huruf kecil, angka, dan tanda hubung. Menjadi bagian dari alamat halaman.')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', app(CurrentTenant::class)->id())),
                Select::make('status')->label('Status')->options(['draft' => 'Draf (belum tampil)', 'published' => 'Terbit'])->default('draft')->required(),
                ColorPicker::make('color')->label('Warna utama')->default('#dc2626')->required()->regex(LandingPage::COLOR_PATTERN),
                Select::make('property_id')->label('Properti yang dipromosikan')->placeholder('Tidak dipilih')
                    ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id'))->searchable()
                    ->helperText('Lead dari halaman ini otomatis tercatat tertarik pada properti ini.'),
                Textarea::make('description')->label('Deskripsi singkat (untuk Google dan pratinjau link)')->rows(2)->maxLength(300),
            ]),
            Section::make('Isi halaman')->columnSpanFull()->description('Susun blok dari atas ke bawah. Seret untuk mengubah urutan.')->schema([
                Builder::make('blocks')->hiddenLabel()->addActionLabel('Tambah blok')->collapsible()->blockNumbers(false)->default(self::starter())
                    ->blocks([
                        Block::make('hero')->label('Judul utama (hero)')->icon(Heroicon::OutlinedPhoto)->schema([
                            TextInput::make('headline')->label('Judul besar')->required()->maxLength(120),
                            TextInput::make('subheadline')->label('Kalimat pendukung')->maxLength(200),
                            TextInput::make('cta_label')->label('Teks tombol')->default('Daftar sekarang')->maxLength(40),
                            $upload('image')->label('Foto latar'),
                        ]),
                        Block::make('highlights')->label('Keunggulan')->icon(Heroicon::OutlinedStar)->schema([
                            TextInput::make('heading')->label('Judul bagian')->default('Kenapa memilih kami')->maxLength(100),
                            Repeater::make('items')->label('Poin')->maxItems(9)->defaultItems(3)->schema([
                                TextInput::make('title')->label('Judul')->required()->maxLength(80),
                                TextInput::make('text')->label('Penjelasan')->maxLength(200),
                            ])->columns(2),
                        ]),
                        Block::make('details')->label('Rincian & harga')->icon(Heroicon::OutlinedTableCells)->schema([
                            TextInput::make('heading')->label('Judul bagian')->default('Rincian unit')->maxLength(100),
                            Repeater::make('rows')->label('Baris')->maxItems(15)->schema([
                                TextInput::make('label')->label('Nama')->required()->maxLength(60),
                                TextInput::make('value')->label('Isi')->required()->maxLength(160),
                            ])->columns(2)->defaultItems(0),
                        ]),
                        Block::make('gallery')->label('Galeri foto')->icon(Heroicon::OutlinedSquares2x2)->schema([
                            TextInput::make('heading')->label('Judul bagian')->default('Galeri')->maxLength(100),
                            $upload('images')->label('Foto')->multiple()->reorderable()->maxFiles(9),
                        ]),
                        Block::make('location')->label('Lokasi & peta')->icon(Heroicon::OutlinedMapPin)->schema([
                            TextInput::make('heading')->label('Judul bagian')->default('Lokasi')->maxLength(100),
                            TextInput::make('address')->label('Alamat')->maxLength(250),
                            TextInput::make('map_url')->label('Alamat embed Google Maps')->url()->maxLength(600)
                                ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => ($value === null || $value === '' || str_starts_with((string) $value, 'https://www.google.com/maps/embed?')) ?: $fail('Pakai alamat dari Google Maps, Bagikan, Sematkan peta (diawali https://www.google.com/maps/embed).'))
                                ->helperText('Di Google Maps: Bagikan, Sematkan peta, salin isi src="..." saja.'),
                        ]),
                        Block::make('text')->label('Teks bebas')->icon(Heroicon::OutlinedBars3BottomLeft)->schema([
                            TextInput::make('heading')->label('Judul bagian')->maxLength(100),
                            Textarea::make('body')->label('Isi')->rows(5)->maxLength(3000)->helperText('Pisahkan paragraf dengan satu baris kosong.'),
                        ]),
                        Block::make('testimonials')->label('Testimoni')->icon(Heroicon::OutlinedChatBubbleBottomCenterText)->schema([
                            TextInput::make('heading')->label('Judul bagian')->default('Kata mereka')->maxLength(100),
                            Repeater::make('items')->label('Testimoni')->maxItems(6)->schema([
                                TextInput::make('name')->label('Nama')->required()->maxLength(80),
                                Textarea::make('quote')->label('Isi testimoni')->required()->rows(2)->maxLength(300),
                            ])->defaultItems(0),
                        ]),
                        Block::make('faq')->label('Tanya jawab')->icon(Heroicon::OutlinedQuestionMarkCircle)->schema([
                            TextInput::make('heading')->label('Judul bagian')->default('Pertanyaan umum')->maxLength(100),
                            Repeater::make('items')->label('Pertanyaan')->maxItems(12)->schema([
                                TextInput::make('q')->label('Pertanyaan')->required()->maxLength(160),
                                Textarea::make('a')->label('Jawaban')->required()->rows(2)->maxLength(500),
                            ])->defaultItems(0),
                        ]),
                        Block::make('form')->label('Formulir pendaftaran')->icon(Heroicon::OutlinedClipboardDocumentList)->schema([
                            TextInput::make('heading')->label('Judul formulir')->default('Daftar dan dapatkan info lengkap')->maxLength(100),
                            TextInput::make('intro')->label('Kalimat pengantar')->maxLength(200),
                            TextInput::make('button')->label('Teks tombol')->default('Kirim')->maxLength(40),
                        ]),
                        Block::make('whatsapp')->label('Tombol WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftRight)->schema([
                            TextInput::make('number')->label('Nomor WhatsApp')->tel()->helperText('Kosongkan bila belum ada; tombol baru tampil setelah nomor diisi.')->maxLength(20)->placeholder('08123456789'),
                            TextInput::make('label')->label('Teks tombol')->default('Chat via WhatsApp')->maxLength(40),
                            TextInput::make('message')->label('Pesan awal')->default('Halo, saya tertarik dengan unit yang ditawarkan.')->maxLength(200),
                        ]),
                    ]),
            ]),
        ]);
    }

    /** What a new page starts with, so a first page needs only text edits. */
    public static function starter(): array
    {
        return [
            ['type' => 'hero', 'data' => ['headline' => 'Hunian nyaman untuk keluarga Anda', 'subheadline' => 'Cicilan ringan, lokasi strategis, siap huni.', 'cta_label' => 'Daftar sekarang']],
            ['type' => 'highlights', 'data' => ['heading' => 'Kenapa memilih kami', 'items' => [
                ['title' => 'Lokasi strategis', 'text' => 'Dekat jalan utama, sekolah, dan pusat perbelanjaan.'],
                ['title' => 'Cicilan ringan', 'text' => 'Tersedia KPR dengan simulasi gratis dari tim kami.'],
                ['title' => 'Legalitas jelas', 'text' => 'Sertifikat dan perizinan lengkap.'],
            ]]],
            ['type' => 'details', 'data' => ['heading' => 'Rincian unit', 'rows' => [
                ['label' => 'Harga mulai', 'value' => 'Rp 000.000.000'],
                ['label' => 'Tipe', 'value' => '36 / 72'],
                ['label' => 'Lokasi', 'value' => 'Kota, Provinsi'],
            ]]],
            ['type' => 'form', 'data' => ['heading' => 'Daftar dan dapatkan info lengkap', 'button' => 'Kirim']],
            ['type' => 'whatsapp', 'data' => ['label' => 'Chat via WhatsApp', 'message' => 'Halo, saya tertarik dengan unit yang ditawarkan.']],
        ];
    }

    public static function previewUrl(LandingPage $page): string
    {
        return URL::temporarySignedRoute('landing.preview', now()->addMinutes(30), [$page->tenant->slug, $page->slug]);
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
