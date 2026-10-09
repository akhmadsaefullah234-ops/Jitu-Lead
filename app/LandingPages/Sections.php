<?php

namespace App\LandingPages;

use App\Support\CurrentTenant;
use Closure;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
        'price' => 'Harga mulai dari + cicilan',
        'gallery' => 'Galeri foto',
        'amenities' => 'Fasilitas',
        'unit_types' => 'Tipe unit',
        'location' => 'Lokasi, peta & akses terdekat',
        'kpr' => 'Simulasi KPR',
        'testimonials' => 'Testimoni',
        'faq' => 'Tanya jawab',
        'legal' => 'Legalitas',
        'video' => 'Video YouTube',
        'cta' => 'Ajakan + tombol WhatsApp',
        'form' => 'Formulir pendaftaran',
        'countdown' => 'Hitung mundur promo',
        'highlights' => 'Keunggulan',
        'details' => 'Rincian & harga',
        'text' => 'Teks bebas',
    ];

    public const LEGAL_DOCS = ['SHM (Sertifikat Hak Milik)', 'SHGB / HGB (Hak Guna Bangunan)', 'PBG (Persetujuan Bangunan Gedung)', 'IMB (izin lama)', 'AJB (Akta Jual Beli)', 'Izin lokasi', 'Pecah sertifikat', 'Bebas sengketa'];

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return list<Block> */
    public static function blocks(): array
    {
        $blocks = [];

        foreach (self::TYPES as $type => $label) {
            $blocks[] = Block::make($type)->icon(self::icon($type))
                ->label(function (?array $state) use ($label) {
                    $hint = $state['heading'] ?? $state['headline'] ?? $state['price'] ?? null;
                    $hint = is_string($hint) ? trim($hint) : '';
                    $hidden = ! empty($state['hidden']) ? ' (disembunyikan)' : '';

                    return $label.($hint !== '' ? ': '.Str::limit($hint, 36) : '').$hidden;
                })
                ->schema([...self::fields($type), ...self::common($type)]);
        }

        return $blocks;
    }

    private static function icon(string $type): Heroicon
    {
        return match ($type) {
            'hero' => Heroicon::OutlinedPhoto, 'price' => Heroicon::OutlinedBanknotes, 'gallery' => Heroicon::OutlinedSquares2x2,
            'amenities' => Heroicon::OutlinedSparkles, 'unit_types' => Heroicon::OutlinedHomeModern, 'location' => Heroicon::OutlinedMapPin,
            'kpr' => Heroicon::OutlinedCalculator, 'testimonials' => Heroicon::OutlinedChatBubbleBottomCenterText, 'faq' => Heroicon::OutlinedQuestionMarkCircle,
            'legal' => Heroicon::OutlinedShieldCheck, 'video' => Heroicon::OutlinedPlayCircle, 'cta' => Heroicon::OutlinedChatBubbleLeftRight,
            'form' => Heroicon::OutlinedClipboardDocumentList, 'countdown' => Heroicon::OutlinedClock, 'highlights' => Heroicon::OutlinedStar,
            'details' => Heroicon::OutlinedTableCells, default => Heroicon::OutlinedBars3BottomLeft,
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

    private static function heading(string $default = '', string $label = 'Judul bagian'): TextInput
    {
        return TextInput::make('heading')->label($label)->default($default)->maxLength(100);
    }

    /** @return list<mixed> */
    private static function fields(string $type): array
    {
        return match ($type) {
            'hero' => [
                TextInput::make('headline')->label('Judul besar')->required()->maxLength(120),
                TextInput::make('subheadline')->label('Kalimat pendukung')->maxLength(200),
                TextInput::make('cta_label')->label('Teks tombol')->default('Daftar sekarang')->maxLength(40),
                self::upload('image')->label('Foto latar'),
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
                TextInput::make('label')->label('Teks tombol')->default('Chat via WhatsApp')->maxLength(40),
                TextInput::make('message')->label('Pesan awal di WhatsApp')->default('Halo, saya tertarik dengan unit yang ditawarkan.')->maxLength(200),
                TextInput::make('number')->label('Nomor khusus (opsional)')->tel()->maxLength(20)
                    ->helperText('Kosongkan untuk memakai nomor WhatsApp di pengaturan halaman.'),
            ],
            'form' => [
                TextInput::make('heading')->label('Judul formulir')->default('Daftar dan dapatkan info lengkap')->maxLength(100),
                TextInput::make('intro')->label('Kalimat pengantar')->maxLength(200),
                TextInput::make('button')->label('Teks tombol')->default('Kirim')->maxLength(40),
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
}
