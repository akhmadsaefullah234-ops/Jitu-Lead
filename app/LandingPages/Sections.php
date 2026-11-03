<?php

namespace App\LandingPages;

use App\LeadForms\FormTemplates;
use App\Models\LandingPage;
use App\Models\LeadForm;
use App\Models\Property;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Closure;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The section library: which sections exist, what the editor asks for in each,
 * and (by name) which public partial draws it. All text fields are plain text;
 * the only formatted field is the rich text of "Teks bebas", which RichText
 * rebuilds from an allow-list when the page is shown.
 */
class Sections
{
    /** type => label. The order here is the order of the "add section" picker. */
    public const TYPES = [
        'hero' => 'Judul utama (hero)',
        'problem_solution' => 'Masalah & solusi',
        'benefits' => 'Manfaat',
        'social_proof' => 'Bukti sosial (testimoni, logo, angka)',
        'closing' => 'Penutup & ajakan utama',
        'slider' => 'Slider gambar',
        'icon_list' => 'Ikon + keterangan',
        'product' => 'Produk (foto, harga promo, varian, stok)',
        'specs' => 'Spesifikasi produk',
        'shop_perks' => 'Keunggulan belanja (ongkir, COD, retur)',
        'reviews' => 'Ulasan pembeli',
        'price' => 'Harga mulai dari + cicilan',
        'gallery' => 'Galeri foto',
        'gif' => 'GIF animasi',
        'amenities' => 'Fasilitas',
        'unit_types' => 'Tipe unit',
        'location' => 'Lokasi, peta & akses terdekat',
        'kpr' => 'Simulasi KPR',
        'testimonials' => 'Testimoni',
        'faq' => 'Tanya jawab (atasi keraguan)',
        'legal' => 'Legalitas',
        'video' => 'Video YouTube',
        'cta' => 'Ajakan + tombol WhatsApp',
        'buttons' => 'Tombol aksi',
        'form' => 'Formulir pendaftaran',
        'countdown' => 'Hitung mundur promo',
        'highlights' => 'Keunggulan',
        'details' => 'Rincian & harga',
        'text' => 'Teks bebas',
    ];

    /** Sections that only make sense for one kind of business; the rest are for everyone. */
    public const ONLY = [
        'price' => 'properti', 'kpr' => 'properti',
        'product' => 'retail', 'specs' => 'retail', 'shop_perks' => 'retail', 'reviews' => 'retail',
    ];

    public const STOCK = ['ready' => 'Ready stok', 'low' => 'Sisa sedikit', 'preorder' => 'Pre-order'];

    /**
     * The sections an agency of this industry can add (null: all). Types already on the page stay editable.
     *
     * @param  list<string>  $keep
     * @return array<string, string>
     */
    public static function types(?string $industry = null, array $keep = []): array
    {
        return array_filter(self::TYPES, fn ($label, $type) => $industry === null || in_array($type, $keep, true)
            || (self::ONLY[$type] ?? $industry) === $industry, ARRAY_FILTER_USE_BOTH);
    }

    public const LEGAL_DOCS = ['SHM (Sertifikat Hak Milik)', 'SHGB / HGB (Hak Guna Bangunan)', 'PBG (Persetujuan Bangunan Gedung)', 'IMB (izin lama)', 'AJB (Akta Jual Beli)', 'Izin lokasi', 'Pecah sertifikat', 'Bebas sengketa'];

    /**
     * The buttons of a hero or "Ajakan" section. Pages saved before buttons could be set up keep their one button.
     *
     * @return list<array<string, mixed>>
     */
    public static function buttonsOf(string $type, array $props): array
    {
        if (isset($props['buttons']) && is_array($props['buttons'])) {
            return array_values(array_filter($props['buttons'], 'is_array'));
        }

        return match ($type) {
            'hero' => [['label' => $props['cta_label'] ?? 'Daftar sekarang', 'action' => 'scroll', 'color' => 'light', 'size' => 'lg']],
            'cta' => [[
                'label' => $props['label'] ?? 'Chat via WhatsApp', 'action' => 'whatsapp', 'color' => 'wa', 'size' => 'lg', 'icon' => 'whatsapp',
                'wa_target' => filled($props['number'] ?? null) ? 'manual' : 'agency', 'wa_number' => $props['number'] ?? null,
                'wa_message' => $props['message'] ?? 'Halo, saya tertarik dengan {judul}.',
            ]],
            default => [],
        };
    }

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * @param  list<string>  $keep  types already on the page
     * @return list<Block>
     */
    public static function blocks(?string $industry = null, array $keep = []): array
    {
        $blocks = [];

        foreach (self::types($industry, $keep) as $type => $label) {
            $blocks[] = Block::make($type)->icon(self::icon($type))
                ->label(function (?array $state) use ($label) {
                    $hint = $state['heading'] ?? $state['headline'] ?? $state['name'] ?? $state['price'] ?? null;
                    $hint = is_string($hint) ? trim($hint) : '';
                    $hidden = ! empty($state['hidden']) ? ' (disembunyikan)' : '';

                    return $label.($hint !== '' ? ': '.Str::limit($hint, 36) : '').$hidden;
                })
                ->schema([...self::fields($type), ...self::common($type), BlockStyle::section()]);
        }

        return $blocks;
    }

    private static function icon(string $type): Heroicon
    {
        return match ($type) {
            'hero' => Heroicon::OutlinedPhoto, 'price' => Heroicon::OutlinedBanknotes, 'gallery' => Heroicon::OutlinedSquares2x2, 'gif' => Heroicon::OutlinedFilm,
            'amenities' => Heroicon::OutlinedSparkles, 'unit_types' => Heroicon::OutlinedHomeModern, 'location' => Heroicon::OutlinedMapPin,
            'kpr' => Heroicon::OutlinedCalculator, 'testimonials' => Heroicon::OutlinedChatBubbleBottomCenterText, 'faq' => Heroicon::OutlinedQuestionMarkCircle,
            'legal' => Heroicon::OutlinedShieldCheck, 'video' => Heroicon::OutlinedPlayCircle, 'cta' => Heroicon::OutlinedChatBubbleLeftRight,
            'form' => Heroicon::OutlinedClipboardDocumentList, 'countdown' => Heroicon::OutlinedClock, 'highlights' => Heroicon::OutlinedStar,
            'problem_solution' => Heroicon::OutlinedLightBulb, 'benefits' => Heroicon::OutlinedHandThumbUp, 'social_proof' => Heroicon::OutlinedUserGroup,
            'closing' => Heroicon::OutlinedFlag, 'slider' => Heroicon::OutlinedRectangleStack, 'icon_list' => Heroicon::OutlinedListBullet,
            'details', 'specs' => Heroicon::OutlinedTableCells, 'product' => Heroicon::OutlinedShoppingBag, 'shop_perks' => Heroicon::OutlinedTruck,
            'reviews' => Heroicon::OutlinedChatBubbleLeftEllipsis, 'buttons' => Heroicon::OutlinedCursorArrowRays, default => Heroicon::OutlinedBars3BottomLeft,
        };
    }

    /** The two switches every section has. */
    private static function common(string $type): array
    {
        return [
            Toggle::make('hidden')->label('Sembunyikan bagian ini (tidak tampil di halaman)')->default(false),
            ...($type === 'hero' ? [] : [
                Select::make('bg')->label('Latar bagian')->default('auto')->native(false)
                    ->options(['auto' => 'Otomatis', 'light' => 'Terang', 'dark' => 'Gelap', 'brand' => 'Warna utama']),
            ]),
        ];
    }

    public static function upload(string $name, int $maxEdge = ImageOptimizer::MAX_EDGE): FileUpload
    {
        return FileUpload::make($name)->image()->disk('public')->visibility('public')
            ->directory(fn () => 'landing/'.app(CurrentTenant::class)->id())
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(8192)->helperText('Foto dari HP boleh langsung dipakai. Ukuran maksimal 8 MB, dikecilkan otomatis.')
            ->saveUploadedFileUsing(fn ($file) => ImageOptimizer::store($file, 'landing/'.app(CurrentTenant::class)->id(), $maxEdge));
    }

    /**
     * An animated picture (GIF, or animated WebP) stored exactly as uploaded: resizing or converting it would keep
     * only the first frame.
     */
    public static function gifUpload(string $name): FileUpload
    {
        return FileUpload::make($name)->disk('public')->visibility('public')
            ->directory(fn () => 'landing/'.app(CurrentTenant::class)->id())
            ->acceptedFileTypes(['image/gif', 'image/webp'])->maxSize(self::GIF_MAX_KB)
            ->helperText('GIF atau WebP animasi, maksimal 9 MB. Disimpan apa adanya agar tetap bergerak; di bawah 3 MB lebih cepat dimuat di HP.');
    }

    public const GIF_MAX_KB = 9216;

    public const GIF_WIDTHS = ['full' => 'Lebar penuh', 'medium' => 'Sedang', 'small' => 'Kecil'];

    private static function heading(string $default = '', string $label = 'Judul bagian'): TextInput
    {
        return TextInput::make('heading')->label($label)->default($default)->maxLength(100);
    }

    /** @return list<mixed> */
    private static function fields(string $type): array
    {
        return match ($type) {
            'hero' => [
                TextInput::make('headline')->label('Judul besar (nilai utama)')->required()->maxLength(120),
                TextInput::make('subheadline')->label('Kalimat pendukung (untuk siapa + manfaatnya)')->maxLength(200),
                Select::make('visual')->label('Visual utama')->options(['image' => 'Gambar (latar)', 'video' => 'Video', 'slider' => 'Slider / galeri gambar'])
                    ->default('image')->native(false)->selectablePlaceholder(false)->live(),
                self::upload('image')->label('Foto latar')->visible(fn (Get $get) => ($get('visual') ?? 'image') === 'image'),
                Grid::make(['default' => 1, 'sm' => 2])->visible(fn (Get $get) => $get('visual') === 'video')->schema([
                    TextInput::make('video_url')->label('Tautan video YouTube atau file MP4')->url()->maxLength(500)->placeholder('https://www.youtube.com/watch?v=...')
                        ->rule(fn () => fn (string $attribute, mixed $value, Closure $fail) => ($value === null || $value === '' || Embeds::youtubeId($value) || Embeds::videoUrl($value)) ?: $fail('Pakai tautan YouTube atau alamat file .mp4 (https).')),
                    FileUpload::make('video_file')->label('atau unggah video MP4')->disk('public')->visibility('public')
                        ->directory(fn () => 'landing/'.app(CurrentTenant::class)->id())->acceptedFileTypes(['video/mp4', 'video/webm'])->maxSize(30720)
                        ->helperText('Maksimal 30 MB. Video pendek (di bawah 30 detik) lebih cepat dimuat.'),
                ]),
                Group::make(Slider::fields('slider_'))->visible(fn (Get $get) => $get('visual') === 'slider'),
                Buttons::repeater('buttons', 'Tombol (utama + kedua opsional)', [['label' => 'Daftar sekarang', 'action' => 'scroll', 'color' => 'light', 'size' => 'lg', 'icon' => 'none']]),
                TextInput::make('assurance')->label('Baris penegas di bawah tombol')->maxLength(120)->placeholder('Gratis ongkir • Bisa COD'),
            ],
            'problem_solution' => [
                self::heading('[Masalah yang sering dialami pembeli]'),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('problem_title')->label('Judul kolom masalah')->default('Sering mengalami ini?')->maxLength(80),
                    TextInput::make('solution_title')->label('Judul kolom solusi')->default('Dengan kami')->maxLength(80),
                ]),
                Repeater::make('problems')->label('Masalah')->maxItems(8)->defaultItems(0)->columns(['default' => 1, 'sm' => 4])->schema([
                    TextInput::make('icon')->label('Ikon / emoji')->default('✕')->maxLength(4),
                    TextInput::make('text')->label('Masalah')->required()->maxLength(160)->columnSpan(['sm' => 3]),
                ]),
                Repeater::make('solutions')->label('Solusi')->maxItems(8)->defaultItems(0)->columns(['default' => 1, 'sm' => 4])->schema([
                    TextInput::make('icon')->label('Ikon / emoji')->default('✓')->maxLength(4),
                    TextInput::make('text')->label('Solusi')->required()->maxLength(160)->columnSpan(['sm' => 3]),
                ]),
            ],
            'benefits' => [
                self::heading('Manfaat untuk Anda'),
                TextInput::make('intro')->label('Kalimat pengantar')->maxLength(200),
                Repeater::make('items')->label('Manfaat')->maxItems(9)->defaultItems(0)->collapsible()
                    ->helperText('Tulis sebagai manfaat yang dirasakan pembeli, bukan fitur teknis. Contoh: "Tidur lebih nyenyak", bukan "Busa 30 cm".')
                    ->itemLabel(fn (array $state) => $state['title'] ?? null)->schema(self::iconItem()),
            ],
            'social_proof' => [
                self::heading('Kata mereka'),
                Placeholder::make('real_data')->hiddenLabel()->content('Gunakan data asli; jangan menampilkan angka/testimoni karangan.'),
                Repeater::make('testimonials')->label('Testimoni')->maxItems(9)->defaultItems(0)->collapsible()
                    ->itemLabel(fn (array $state) => $state['name'] ?? null)->schema([
                        self::upload('photo', 800)->label('Foto'),
                        Grid::make(['default' => 1, 'sm' => 3])->schema([
                            TextInput::make('name')->label('Nama')->required()->maxLength(80),
                            TextInput::make('role')->label('Kota / peran')->maxLength(80)->placeholder('Bandung'),
                            Select::make('rating')->label('Bintang')->options([5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1'])->placeholder('Tanpa bintang')->native(false),
                        ]),
                        Textarea::make('quote')->label('Kutipan')->required()->rows(2)->maxLength(300),
                    ]),
                TextInput::make('logos_title')->label('Judul deretan logo')->maxLength(80)->placeholder('Telah diliput / bekerja sama dengan'),
                self::upload('logos', 600)->label('Logo mitra / media')->multiple()->reorderable()->maxFiles(12),
                Repeater::make('stats')->label('Angka statistik')->maxItems(4)->defaultItems(0)->columns(2)->schema([
                    TextInput::make('number')->label('Angka')->required()->maxLength(20)->placeholder('[1.200+]'),
                    TextInput::make('label')->label('Keterangan')->required()->maxLength(60)->placeholder('[pembeli puas]'),
                ]),
            ],
            'closing' => [
                self::heading('[Ajakan penutup]', 'Judul ajakan'),
                TextInput::make('text')->label('Kalimat pendukung')->maxLength(200),
                TagsInput::make('assurances')->label('Penawaran penegas')->placeholder('Ketik lalu Enter, mis. Garansi 7 hari')
                    ->helperText('Ditampilkan sebagai poin kecil, misalnya garansi, retur, atau COD.'),
                Buttons::repeater('buttons', 'Tombol', [['label' => 'Pesan sekarang', 'action' => 'scroll', 'color' => 'brand', 'size' => 'lg', 'icon' => 'none']]),
            ],
            'slider' => [
                self::heading(''),
                ...Slider::fields(),
            ],
            'icon_list' => [
                self::heading(''),
                Repeater::make('items')->label('Item')->maxItems(12)->defaultItems(0)->collapsible()
                    ->itemLabel(fn (array $state) => $state['title'] ?? null)->schema([...self::iconItem(), TextInput::make('link')->label('Tautan (opsional)')->url()->maxLength(500)]),
                Fieldset::make('Tampilan ikon')->columns(['default' => 2, 'sm' => 3])->schema([
                    Select::make('layout')->label('Tata letak')->options(['list' => 'Daftar (ikon di kiri)', 'grid' => 'Kotak (ikon di atas)'])->default('list')->native(false),
                    Select::make('cols_desktop')->label('Kolom di PC')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4'])->default(2)->native(false),
                    Select::make('cols_mobile')->label('Kolom di HP')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4'])->default(1)->native(false),
                    Select::make('icon_pos')->label('Posisi ikon')->options(['left' => 'Kiri', 'top' => 'Atas', 'right' => 'Kanan'])->placeholder('Ikuti tata letak')->native(false),
                    TextInput::make('icon_size')->label('Ukuran ikon')->numeric()->minValue(16)->maxValue(96)->default(28)->suffix('px'),
                    Select::make('icon_shape')->label('Latar ikon')->options(['circle' => 'Lingkaran', 'square' => 'Kotak', 'none' => 'Tanpa latar'])->default('circle')->native(false),
                    ColorPicker::make('icon_color')->label('Warna ikon'),
                    ColorPicker::make('icon_bg')->label('Warna latar ikon'),
                    Toggle::make('divider')->label('Garis pemisah')->inline(false),
                ]),
            ],
            'product' => [
                Select::make('property_id')->label('Ambil data dari katalog Produk')->placeholder('Isi manual')->native(false)->searchable()->live()
                    ->options(fn () => Property::query()->orderBy('name')->pluck('name', 'id'))
                    ->afterStateUpdated(fn ($state, Set $set) => self::fillFromCatalog($state, $set))
                    ->helperText('Nama, harga, varian, dan ukuran diisi dari katalog (rincian "Warna", "Varian", atau "Ukuran"). Foto diunggah di sini.'),
                TextInput::make('name')->label('Nama produk')->required()->maxLength(120),
                self::upload('images')->label('Foto produk (pengunjung bisa menggeser)')->multiple()->reorderable()->maxFiles(10),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('price')->label('Harga normal (Rp)')->numeric()->minValue(0)->maxValue(1000000000)->prefix('Rp'),
                    TextInput::make('promo_price')->label('Harga promo (Rp)')->numeric()->minValue(0)->maxValue(1000000000)->prefix('Rp')
                        ->helperText('Kosongkan bila tidak promo. Harga normal dicoret dan badge diskon % dihitung otomatis.'),
                    Select::make('stock')->label('Status stok')->options(self::STOCK)->default('ready')->native(false),
                    TextInput::make('stock_note')->label('Keterangan stok')->maxLength(60)->placeholder('Sisa 7 pcs / dikirim 7 hari'),
                ]),
                TextInput::make('variant_label')->label('Nama pilihan varian')->default('Warna')->maxLength(30),
                Repeater::make('variants')->label('Varian')->maxItems(12)->defaultItems(0)->collapsible()
                    ->itemLabel(fn (array $state) => $state['name'] ?? null)->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            TextInput::make('name')->label('Nama varian')->required()->maxLength(40)->placeholder('Hitam'),
                            ColorPicker::make('swatch')->label('Warna contoh (swatch)')->regex(LandingPage::COLOR_PATTERN),
                        ]),
                        self::upload('images')->label('Foto khusus varian ini (opsional)')->multiple()->reorderable()->maxFiles(6),
                    ]),
                TagsInput::make('sizes')->label('Ukuran (opsional)')->placeholder('Ketik ukuran lalu Enter, mis. S, M, L'),
                Repeater::make('points')->label('Poin keunggulan')->maxItems(8)->defaultItems(0)->simple(
                    TextInput::make('text')->required()->maxLength(120)->placeholder('Bahan adem dan tidak menerawang'),
                ),
                Buttons::repeater('buttons', 'Tombol', [['label' => 'Pesan Sekarang', 'action' => 'scroll', 'color' => 'brand', 'size' => 'lg', 'icon' => 'cart']]),
            ],
            'specs' => [
                self::heading('Spesifikasi'),
                Repeater::make('rows')->label('Baris')->maxItems(15)->defaultItems(0)->columns(2)->schema([
                    TextInput::make('label')->label('Nama')->required()->maxLength(60)->datalist(['Ukuran', 'Bahan', 'Kapasitas', 'Berat', 'Isi paket', 'Garansi']),
                    TextInput::make('value')->label('Isi')->required()->maxLength(160),
                ]),
            ],
            'shop_perks' => [
                self::heading('Belanja aman di sini'),
                Repeater::make('items')->label('Keunggulan')->maxItems(8)->defaultItems(0)->columns(3)->schema([
                    Select::make('icon')->label('Ikon')->options(Icons::options())->default('truck')->native(false),
                    TextInput::make('title')->label('Judul')->required()->maxLength(60),
                    TextInput::make('text')->label('Keterangan')->maxLength(120),
                ]),
            ],
            'reviews' => [
                self::heading('Ulasan pembeli'),
                Repeater::make('items')->label('Ulasan')->maxItems(9)->defaultItems(0)->collapsible()
                    ->helperText('Isi dengan ulasan asli dari pembeli Anda (dengan izin mereka). Ulasan karangan bisa menyesatkan pembeli dan melanggar aturan iklan.')
                    ->itemLabel(fn (array $state) => $state['name'] ?? null)->schema([
                        self::upload('photo', 800)->label('Foto pembeli atau foto produk yang diterima'),
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            TextInput::make('name')->label('Nama')->required()->maxLength(80),
                            Select::make('rating')->label('Bintang')->options([5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1'])->default(5)->native(false),
                        ]),
                        Textarea::make('quote')->label('Isi ulasan')->required()->rows(2)->maxLength(300),
                    ]),
            ],
            'price' => [
                TextInput::make('label')->label('Keterangan kecil')->default('Harga mulai dari')->maxLength(60),
                TextInput::make('price')->label('Harga')->placeholder('Rp 450 juta')->required()->maxLength(60),
                TextInput::make('installment')->label('Cicilan')->placeholder('Cicilan mulai Rp 2,5 juta per bulan')->maxLength(120),
                TextInput::make('note')->label('Catatan kecil')->placeholder('Harga dapat berubah sewaktu-waktu')->maxLength(160),
            ],
            'gallery' => [
                self::heading('Galeri'),
                self::upload('images')->label('Foto')->multiple()->reorderable()->maxFiles(12),
            ],
            'gif' => [
                self::heading(''),
                self::gifUpload('gifs')->label('GIF')->multiple()->reorderable()->maxFiles(6)->required(),
                TextInput::make('caption')->label('Keterangan di bawah GIF (opsional)')->maxLength(200),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('width')->label('Ukuran')->options(self::GIF_WIDTHS)->default('medium')->native(false)->selectablePlaceholder(false),
                    Select::make('columns')->label('Bila lebih dari satu GIF')->options(['1' => 'Bersusun ke bawah', '2' => '2 kolom', '3' => '3 kolom (2 di HP)'])
                        ->default('2')->native(false)->selectablePlaceholder(false),
                ]),
            ],
            'amenities' => [
                self::heading('Fasilitas'),
                Repeater::make('items')->label('Fasilitas')->maxItems(12)->defaultItems(0)->columns(3)->schema([
                    Select::make('icon')->label('Ikon')->options(Icons::options())->default('check')->native(false),
                    TextInput::make('title')->label('Nama')->required()->maxLength(60),
                    TextInput::make('text')->label('Keterangan')->maxLength(120),
                ]),
            ],
            'unit_types' => [
                self::heading('Pilihan tipe unit'),
                Repeater::make('items')->label('Tipe')->maxItems(8)->defaultItems(0)->columns(3)->schema([
                    TextInput::make('name')->label('Nama tipe')->required()->maxLength(60)->placeholder('Tipe 36/72'),
                    TextInput::make('area')->label('Luas')->maxLength(60)->placeholder('LB 36 m² / LT 72 m²'),
                    TextInput::make('price')->label('Harga')->maxLength(60)->placeholder('Rp 450 juta'),
                    TextInput::make('bedrooms')->label('Kamar tidur')->maxLength(10)->placeholder('2'),
                    TextInput::make('bathrooms')->label('Kamar mandi')->maxLength(10)->placeholder('1'),
                    TextInput::make('note')->label('Catatan')->maxLength(100)->placeholder('Sisa 5 unit'),
                ]),
            ],
            'location' => [
                self::heading('Lokasi'),
                TextInput::make('address')->label('Alamat')->maxLength(250),
                TextInput::make('map_url')->label('Peta: alamat sematan Google Maps')->url()->maxLength(600)
                    ->rule(fn () => fn (string $attribute, mixed $value, Closure $fail) => ($value === null || $value === '' || Embeds::map($value)) ?: $fail('Pakai alamat sematan dari Google Maps: Bagikan, Sematkan peta, salin isi src="..." (diawali https://www.google.com/maps/embed).'))
                    ->helperText('Di Google Maps: Bagikan, tab Sematkan peta, lalu salin hanya isi src="..." di dalam tanda kutip.'),
                TextInput::make('map_link')->label('Tombol rute: tautan Google Maps biasa')->url()->maxLength(300)
                    ->rule(fn () => fn (string $attribute, mixed $value, Closure $fail) => ($value === null || $value === '' || Embeds::mapLink($value)) ?: $fail('Pakai tautan dari Google Maps (maps.app.goo.gl atau google.com/maps).'))
                    ->helperText('Opsional. Tautan dari tombol Bagikan di Google Maps.'),
                Repeater::make('nearby')->label('Akses terdekat')->maxItems(10)->defaultItems(0)->columns(2)->schema([
                    TextInput::make('place')->label('Tempat')->required()->maxLength(80)->placeholder('Gerbang tol'),
                    TextInput::make('distance')->label('Jarak / waktu')->maxLength(40)->placeholder('5 menit'),
                ]),
            ],
            'kpr' => [
                self::heading('Simulasi cicilan KPR'),
                TextInput::make('price')->label('Harga properti awal (Rupiah, angka saja)')->numeric()->default(450000000)->minValue(1)->maxValue(100000000000),
                TextInput::make('dp_percent')->label('Uang muka awal (%)')->numeric()->default(10)->minValue(0)->maxValue(90),
                TextInput::make('tenor')->label('Tenor awal (tahun)')->numeric()->default(20)->minValue(1)->maxValue(30),
                TextInput::make('rate')->label('Bunga awal (% per tahun)')->numeric()->default(8)->minValue(0)->maxValue(30)
                    ->helperText('Pengunjung bisa mengubah semua angka ini di halaman. Hasilnya hanya perkiraan.'),
                TextInput::make('note')->label('Catatan')->default('Simulasi hanya perkiraan, bukan penawaran bank. Hubungi kami untuk perhitungan resmi.')->maxLength(200),
            ],
            'testimonials' => [
                self::heading('Kata mereka'),
                Repeater::make('items')->label('Testimoni')->maxItems(6)->defaultItems(0)->schema([
                    TextInput::make('name')->label('Nama')->required()->maxLength(80),
                    Textarea::make('quote')->label('Isi testimoni')->required()->rows(2)->maxLength(300),
                ]),
            ],
            'faq' => [
                self::heading('Pertanyaan umum'),
                Repeater::make('items')->label('Pertanyaan')->maxItems(12)->defaultItems(0)->schema([
                    TextInput::make('q')->label('Pertanyaan')->required()->maxLength(160),
                    Textarea::make('a')->label('Jawaban')->required()->rows(2)->maxLength(500),
                ]),
            ],
            'legal' => [
                self::heading('Legalitas'),
                Repeater::make('items')->label('Dokumen')->maxItems(10)->defaultItems(0)->columns(2)->schema([
                    TextInput::make('name')->label('Dokumen')->required()->maxLength(80)->datalist(self::LEGAL_DOCS),
                    TextInput::make('note')->label('Keterangan')->maxLength(120)->placeholder('Sudah tersedia / dalam proses'),
                ]),
                TextInput::make('note')->label('Catatan kecil')->maxLength(200)->placeholder('Dokumen asli dapat dilihat saat survei lokasi.'),
            ],
            'video' => [
                self::heading('Lihat videonya'),
                TextInput::make('url')->label('Tautan video YouTube')->url()->maxLength(200)->placeholder('https://www.youtube.com/watch?v=...')
                    ->rule(fn () => fn (string $attribute, mixed $value, Closure $fail) => ($value === null || $value === '' || Embeds::youtubeId($value)) ?: $fail('Pakai tautan video YouTube (youtube.com atau youtu.be).')),
            ],
            'cta' => [
                self::heading('Tertarik? Tanyakan langsung', 'Judul'),
                TextInput::make('text')->label('Kalimat pendukung')->maxLength(200),
                Buttons::repeater('buttons', 'Tombol', [['label' => 'Chat via WhatsApp', 'action' => 'whatsapp', 'wa_target' => 'agency', 'wa_message' => 'Halo, saya tertarik dengan {judul}.', 'color' => 'wa', 'size' => 'lg', 'icon' => 'whatsapp']]),
            ],
            'buttons' => [
                self::heading(''),
                TextInput::make('text')->label('Kalimat pendukung')->maxLength(200),
                Select::make('align')->label('Posisi')->options(['center' => 'Tengah', 'left' => 'Kiri', 'full' => 'Lebar penuh'])->default('center')->native(false),
                Buttons::repeater('buttons', 'Tombol', [['label' => 'Chat via WhatsApp', 'action' => 'whatsapp', 'wa_target' => 'rotate', 'color' => 'wa', 'size' => 'lg', 'icon' => 'whatsapp']], 4),
            ],
            'form' => [
                Select::make('form_id')->label('Formulir yang dipakai')->placeholder('Formulir utama')->native(false)
                    ->options(fn () => LeadForm::query()->orderByDesc('is_primary')->orderBy('name')->pluck('name', 'id'))
                    ->helperText('Atur kolomnya di menu Formulir.')
                    ->createOptionForm(fn () => Industry::current()->key === 'retail' ? [
                        TextInput::make('product')->label('Nama produk')->required()->maxLength(60)
                            ->helperText('Dibuat dari template "Order produk": varian, jumlah, alamat lengkap, Transfer/COD, lalu ke WhatsApp admin.'),
                        TagsInput::make('variants')->label('Varian / warna')->placeholder('Ketik lalu Enter'),
                    ] : null)
                    ->createOptionModalHeading('Buat formulir "Order produk"')
                    ->createOptionUsing(fn (array $data) => FormTemplates::createOrderForm((string) ($data['product'] ?? ''), (array) ($data['variants'] ?? []))->getKey()),
                TextInput::make('heading')->label('Judul formulir')->default('Daftar dan dapatkan info lengkap')->maxLength(100),
                TextInput::make('intro')->label('Kalimat pengantar')->maxLength(200),
                TextInput::make('button')->label('Teks tombol (opsional)')->maxLength(40)->helperText('Kosongkan untuk memakai teks tombol dari formulirnya.'),
            ],
            'countdown' => [
                self::heading('Promo berakhir dalam'),
                DateTimePicker::make('ends_at')->label('Promo berakhir pada (waktu WIB)')->seconds(false)->timezone('Asia/Jakarta')->required(),
                TextInput::make('ended_text')->label('Teks setelah promo berakhir')->default('Promo telah berakhir. Hubungi kami untuk penawaran terbaru.')->maxLength(160),
            ],
            'highlights' => [
                self::heading('Kenapa memilih kami'),
                Repeater::make('items')->label('Poin')->maxItems(9)->defaultItems(3)->columns(2)->schema([
                    TextInput::make('title')->label('Judul')->required()->maxLength(80),
                    TextInput::make('text')->label('Penjelasan')->maxLength(200),
                ]),
            ],
            'details' => [
                self::heading('Rincian unit'),
                Repeater::make('rows')->label('Baris')->maxItems(15)->defaultItems(0)->columns(2)->schema([
                    TextInput::make('label')->label('Nama')->required()->maxLength(60),
                    TextInput::make('value')->label('Isi')->required()->maxLength(160),
                ]),
            ],
            default => [
                self::heading(),
                RichEditor::make('html')->label('Isi')->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList']])
                    ->helperText('Hanya tebal, miring, daftar, dan tautan yang ditampilkan.'),
            ],
        };
    }

    /** Name, price, variants and sizes of a catalog product, for the product section. */
    public static function fromCatalog(mixed $propertyId): ?array
    {
        $product = is_numeric($propertyId) ? Property::query()->find((int) $propertyId) : null;

        if ($product === null) {
            return null;
        }

        $variants = [];
        $sizes = [];
        $specs = [];

        foreach ((array) $product->details as $label => $value) {
            $list = array_values(array_filter(array_map('trim', preg_split('/[,;\/]+/', (string) $value)), 'strlen'));

            if (preg_match('/warna|varian|color|motif/i', (string) $label)) {
                $variants = [...$variants, ...$list];
            } elseif (preg_match('/ukuran|size/i', (string) $label) && count($list) > 1) {
                $sizes = [...$sizes, ...$list];
            } else {
                $specs[] = ['label' => (string) $label, 'value' => (string) $value];
            }
        }

        return [
            'name' => $product->name,
            'price' => $product->price_from !== null ? (int) $product->price_from : null,
            'variants' => array_values(array_unique($variants)),
            'sizes' => array_values(array_unique($sizes)),
            'specs' => $specs,
        ];
    }

    private static function fillFromCatalog(mixed $propertyId, Set $set): void
    {
        if (! $data = self::fromCatalog($propertyId)) {
            return;
        }

        $set('name', $data['name']);

        if ($data['price'] !== null) {
            $set('price', $data['price']);
        }

        if ($data['variants'] !== []) {
            $set('variants', collect($data['variants'])->mapWithKeys(fn ($v) => [(string) Str::uuid() => ['name' => $v, 'swatch' => null, 'images' => []]])->all());
        }

        if ($data['sizes'] !== []) {
            $set('sizes', $data['sizes']);
        }
    }

    /**
     * Prices of a product section: what is paid now, the struck-through normal price and the discount badge.
     *
     * @return array{now: ?int, was: ?int, off: int}
     */
    public static function prices(array $props): array
    {
        $price = is_numeric($props['price'] ?? null) ? (int) $props['price'] : 0;
        $promo = is_numeric($props['promo_price'] ?? null) ? (int) $props['promo_price'] : 0;

        if ($promo > 0 && $price > $promo) {
            return ['now' => $promo, 'was' => $price, 'off' => (int) round(($price - $promo) / $price * 100)];
        }

        $now = $promo > 0 ? $promo : $price;

        return ['now' => $now > 0 ? $now : null, 'was' => null, 'off' => 0];
    }

    public static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    /** One item with an icon from the set, an emoji, or an uploaded picture. */
    private static function iconItem(): array
    {
        return [
            Grid::make(['default' => 1, 'sm' => 3])->schema([
                Select::make('icon_type')->label('Jenis ikon')->options(['icon' => 'Ikon bawaan', 'emoji' => 'Emoji', 'image' => 'Gambar sendiri'])->default('icon')->native(false)->live(),
                Select::make('icon')->label('Ikon')->options(Icons::options())->default('check')->native(false)->visible(fn (Get $get) => ($get('icon_type') ?? 'icon') === 'icon'),
                TextInput::make('emoji')->label('Emoji')->maxLength(8)->placeholder('🚚')->visible(fn (Get $get) => $get('icon_type') === 'emoji'),
                self::upload('icon_image', 256)->label('Gambar ikon')->visible(fn (Get $get) => $get('icon_type') === 'image'),
                TextInput::make('title')->label('Judul')->required()->maxLength(80)->columnSpan(['sm' => 2]),
            ]),
            TextInput::make('text')->label('Keterangan (opsional)')->maxLength(200),
        ];
    }
}
