<?php

namespace App\LandingPages;

/**
 * Starter pages: a section order plus Indonesian sample copy to be replaced.
 * Nothing here promises returns, discounts, or approval; the texts are
 * placeholders the agent edits before publishing.
 */
class Templates
{
    public const BLANK = 'kosong';

    /** @return array<string, array{name: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description']], self::all());
    }

    /** @return array{name: string, description: string, color: string, font: string, meta: string, blocks: list<array<string, mixed>>} */
    public static function get(?string $key): array
    {
        return self::all()[$key] ?? self::all()[self::BLANK];
    }

    /**
     * @return list<array{type: string, version: int, props: array<string, mixed>}>
     */
    public static function blocks(?string $key): array
    {
        return BlockFormat::normalize(self::get($key)['blocks']);
    }

    private static function b(string $type, array $props = []): array
    {
        return ['type' => $type, 'version' => BlockFormat::VERSION, 'props' => $props];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $form = self::b('form', ['heading' => 'Daftar dan dapatkan info lengkap', 'intro' => 'Isi nama dan nomor WhatsApp, tim kami akan menghubungi Anda.', 'button' => 'Kirim']);
        $cta = self::b('cta', ['heading' => 'Tertarik? Tanyakan langsung', 'text' => 'Tim kami siap menjawab pertanyaan Anda.', 'label' => 'Chat via WhatsApp', 'message' => 'Halo, saya tertarik dengan unit yang ditawarkan.']);

        return [
            self::BLANK => [
                'name' => 'Halaman kosong', 'description' => 'Mulai dari nol: hanya judul dan formulir pendaftaran.',
                'color' => '#dc2626', 'font' => 'modern', 'meta' => '',
                'blocks' => [self::b('hero', ['headline' => 'Judul halaman Anda', 'subheadline' => 'Tulis kalimat pendukung di sini.', 'cta_label' => 'Daftar sekarang']), $form],
            ],

            'kpr' => [
                'name' => 'Rumah KPR / subsidi', 'description' => 'Fokus cicilan, uang muka, dan syarat KPR. Cocok untuk rumah pertama.',
                'color' => '#16a34a', 'font' => 'ramah', 'meta' => 'Rumah dengan cicilan ringan dan proses KPR dibantu. Daftar untuk simulasi gratis.',
                'blocks' => [
                    self::b('hero', ['headline' => 'Punya rumah sendiri dengan cicilan ringan', 'subheadline' => 'Proses KPR dibantu dari awal sampai akad. Daftar untuk simulasi gratis.', 'cta_label' => 'Minta simulasi gratis']),
                    self::b('price', ['label' => 'Harga mulai dari', 'price' => 'Rp 000 juta', 'installment' => 'Cicilan mulai Rp 0,0 juta per bulan', 'note' => 'Harga dan cicilan dapat berubah sesuai kebijakan bank dan pengembang.']),
                    self::b('kpr', ['heading' => 'Hitung cicilan Anda', 'price' => 200000000, 'dp_percent' => 10, 'tenor' => 20, 'rate' => 8, 'note' => 'Simulasi hanya perkiraan, bukan penawaran bank. Hubungi kami untuk perhitungan resmi.']),
                    self::b('text', ['heading' => 'Syarat umum KPR', 'html' => '<ul><li>Warga negara Indonesia dan sudah berusia dewasa.</li><li>Memiliki penghasilan tetap atau usaha yang berjalan.</li><li>Identitas dan kelengkapan dokumen sesuai ketentuan bank.</li></ul><p>Syarat dapat berbeda di tiap bank dan program. Tim kami membantu memeriksa kelayakan Anda.</p>']),
                    self::b('amenities', ['heading' => 'Yang Anda dapatkan', 'items' => [
                        ['icon' => 'home', 'title' => 'Rumah siap huni', 'text' => 'Tipe sederhana, nyaman untuk keluarga.'],
                        ['icon' => 'water', 'title' => 'Air bersih', 'text' => 'Sumber air yang terjamin.'],
                        ['icon' => 'bolt', 'title' => 'Listrik terpasang', 'text' => 'Sudah siap dipakai.'],
                        ['icon' => 'road', 'title' => 'Akses jalan', 'text' => 'Dekat jalan utama.'],
                    ]]),
                    self::b('location', ['heading' => 'Lokasi', 'address' => 'Alamat lengkap perumahan', 'nearby' => [['place' => 'Sekolah', 'distance' => '5 menit'], ['place' => 'Pasar', 'distance' => '10 menit']]]),
                    self::b('faq', ['heading' => 'Pertanyaan umum', 'items' => [
                        ['q' => 'Berapa uang muka yang dibutuhkan?', 'a' => 'Besarnya tergantung program dan bank. Tim kami akan menjelaskan pilihan yang sesuai dengan kemampuan Anda.'],
                        ['q' => 'Apakah penghasilan pas-pasan bisa mengajukan KPR?', 'a' => 'Bisa dicoba. Daftar dulu, kami bantu cek kelayakan tanpa biaya.'],
                        ['q' => 'Berapa lama proses KPR?', 'a' => 'Lama proses berbeda di tiap bank. Kami akan mendampingi sampai selesai.'],
                    ]]),
                    $form, $cta,
                ],
            ],

            'cluster' => [
                'name' => 'Cluster / perumahan premium', 'description' => 'Fokus fasilitas, tipe unit, dan lokasi. Tampilan elegan.',
                'color' => '#1d4ed8', 'font' => 'elegan', 'meta' => 'Hunian cluster dengan fasilitas lengkap dan lokasi strategis. Jadwalkan kunjungan.',
                'blocks' => [
                    self::b('hero', ['headline' => 'Hunian eksklusif dengan lingkungan yang tertata', 'subheadline' => 'Cluster dengan keamanan 24 jam, taman, dan akses mudah ke pusat kota.', 'cta_label' => 'Jadwalkan kunjungan']),
                    self::b('price', ['label' => 'Harga mulai dari', 'price' => 'Rp 0,0 miliar', 'installment' => 'Tersedia berbagai skema pembayaran', 'note' => 'Harga dapat berubah sewaktu-waktu.']),
                    self::b('gallery', ['heading' => 'Galeri', 'images' => []]),
                    self::b('amenities', ['heading' => 'Fasilitas cluster', 'items' => [
                        ['icon' => 'shield', 'title' => 'Keamanan 24 jam', 'text' => 'Satpam dan one gate system.'],
                        ['icon' => 'cctv', 'title' => 'CCTV', 'text' => 'Pemantauan area cluster.'],
                        ['icon' => 'tree', 'title' => 'Taman dan jogging track', 'text' => 'Area hijau untuk keluarga.'],
                        ['icon' => 'pool', 'title' => 'Kolam renang', 'text' => 'Fasilitas bersama penghuni.'],
                        ['icon' => 'worship', 'title' => 'Tempat ibadah', 'text' => 'Di dalam kawasan.'],
                        ['icon' => 'wifi', 'title' => 'Siap internet', 'text' => 'Jaringan fiber tersedia.'],
                    ]]),
                    self::b('unit_types', ['heading' => 'Pilihan tipe unit', 'items' => [
                        ['name' => 'Tipe A', 'area' => 'LB 90 m² / LT 120 m²', 'price' => 'Rp 0,0 miliar', 'bedrooms' => '3', 'bathrooms' => '2', 'note' => ''],
                        ['name' => 'Tipe B', 'area' => 'LB 120 m² / LT 150 m²', 'price' => 'Rp 0,0 miliar', 'bedrooms' => '4', 'bathrooms' => '3', 'note' => ''],
                    ]]),
                    self::b('location', ['heading' => 'Lokasi strategis', 'address' => 'Alamat lengkap cluster', 'nearby' => [['place' => 'Akses tol', 'distance' => '5 menit'], ['place' => 'Mal / pusat belanja', 'distance' => '10 menit'], ['place' => 'Sekolah', 'distance' => '7 menit']]]),
                    self::b('legal', ['heading' => 'Legalitas', 'items' => [['name' => 'SHM (Sertifikat Hak Milik)', 'note' => ''], ['name' => 'PBG (Persetujuan Bangunan Gedung)', 'note' => '']], 'note' => 'Dokumen asli dapat dilihat saat survei lokasi.']),
                    self::b('testimonials', ['heading' => 'Kata penghuni', 'items' => [['name' => 'Nama penghuni', 'quote' => 'Tulis testimoni asli penghuni di sini.']]]),
                    $form, $cta,
                ],
            ],

            'villa' => [
                'name' => 'Villa / properti investasi', 'description' => 'Fokus lokasi, potensi sewa, dan legalitas. Tanpa janji angka keuntungan.',
                'color' => '#0f766e', 'font' => 'elegan', 'meta' => 'Villa di lokasi wisata dengan legalitas jelas. Hubungi kami untuk informasi dan kunjungan.',
                'blocks' => [
                    self::b('hero', ['headline' => 'Villa di lokasi wisata favorit', 'subheadline' => 'Cocok untuk dihuni sendiri maupun disewakan. Legalitas bisa diperiksa langsung.', 'cta_label' => 'Minta informasi lengkap']),
                    self::b('price', ['label' => 'Harga mulai dari', 'price' => 'Rp 0,0 miliar', 'installment' => 'Skema pembayaran dapat dibicarakan', 'note' => 'Harga dapat berubah sewaktu-waktu.']),
                    self::b('gallery', ['heading' => 'Galeri', 'images' => []]),
                    self::b('video', ['heading' => 'Lihat videonya', 'url' => '']),
                    self::b('highlights', ['heading' => 'Potensi sewa', 'items' => [
                        ['title' => 'Lokasi wisata', 'text' => 'Dekat tujuan wisata yang ramai dikunjungi.'],
                        ['title' => 'Bisa disewakan', 'text' => 'Tersedia pilihan pengelolaan sewa harian atau bulanan.'],
                        ['title' => 'Dikelola profesional', 'text' => 'Informasi pengelolaan dijelaskan oleh tim kami.'],
                    ]]),
                    self::b('text', ['heading' => 'Catatan penting', 'html' => '<p>Pendapatan dari sewa bergantung pada musim, tingkat hunian, dan pengelolaan, sehingga tidak dapat dijamin. Kami menyarankan Anda menghitung sendiri sesuai kebutuhan.</p>']),
                    self::b('amenities', ['heading' => 'Fasilitas', 'items' => [
                        ['icon' => 'pool', 'title' => 'Kolam renang pribadi', 'text' => ''],
                        ['icon' => 'tree', 'title' => 'Taman', 'text' => ''],
                        ['icon' => 'car', 'title' => 'Parkir luas', 'text' => ''],
                        ['icon' => 'wifi', 'title' => 'Internet', 'text' => ''],
                    ]]),
                    self::b('location', ['heading' => 'Lokasi', 'address' => 'Alamat lengkap villa', 'nearby' => [['place' => 'Pantai / objek wisata', 'distance' => '10 menit'], ['place' => 'Bandara', 'distance' => '45 menit']]]),
                    self::b('legal', ['heading' => 'Legalitas', 'items' => [['name' => 'SHM (Sertifikat Hak Milik)', 'note' => ''], ['name' => 'PBG (Persetujuan Bangunan Gedung)', 'note' => ''], ['name' => 'Bebas sengketa', 'note' => '']], 'note' => 'Kami mempersilakan Anda memeriksa dokumen asli dan menggunakan notaris pilihan Anda.']),
                    self::b('faq', ['heading' => 'Pertanyaan umum', 'items' => [
                        ['q' => 'Apakah villa bisa dibeli oleh orang asing?', 'a' => 'Ketentuan kepemilikan berbeda-beda. Hubungi kami untuk penjelasan sesuai kasus Anda.'],
                        ['q' => 'Apakah ada pengelola yang menyewakan?', 'a' => 'Kami dapat menjelaskan pilihan pengelolaan. Tidak ada jaminan tingkat hunian atau pendapatan.'],
                    ]]),
                    $form, $cta,
                ],
            ],

            'kavling' => [
                'name' => 'Kavling / tanah', 'description' => 'Fokus ukuran kavling, harga per meter, legalitas, dan lokasi.',
                'color' => '#b45309', 'font' => 'modern', 'meta' => 'Kavling siap bangun dengan legalitas jelas dan cicilan fleksibel. Daftar untuk survei lokasi.',
                'blocks' => [
                    self::b('hero', ['headline' => 'Kavling siap bangun di lokasi yang terus berkembang', 'subheadline' => 'Legalitas jelas, akses jalan sudah tersedia. Jadwalkan survei lokasi.', 'cta_label' => 'Jadwalkan survei']),
                    self::b('price', ['label' => 'Harga mulai dari', 'price' => 'Rp 0 juta per m²', 'installment' => 'Tersedia pembayaran bertahap', 'note' => 'Harga dapat berubah sewaktu-waktu.']),
                    self::b('unit_types', ['heading' => 'Pilihan kavling', 'items' => [
                        ['name' => 'Kavling 100 m²', 'area' => '100 m²', 'price' => 'Rp 0 juta', 'bedrooms' => '', 'bathrooms' => '', 'note' => ''],
                        ['name' => 'Kavling 150 m²', 'area' => '150 m²', 'price' => 'Rp 0 juta', 'bedrooms' => '', 'bathrooms' => '', 'note' => ''],
                    ]]),
                    self::b('legal', ['heading' => 'Legalitas tanah', 'items' => [['name' => 'SHM (Sertifikat Hak Milik)', 'note' => ''], ['name' => 'Pecah sertifikat', 'note' => ''], ['name' => 'Bebas sengketa', 'note' => '']], 'note' => 'Dokumen asli dapat diperiksa saat survei lokasi.']),
                    self::b('amenities', ['heading' => 'Keunggulan kawasan', 'items' => [
                        ['icon' => 'road', 'title' => 'Akses jalan', 'text' => 'Jalan utama sudah tersedia.'],
                        ['icon' => 'bolt', 'title' => 'Listrik', 'text' => 'Jaringan listrik sudah ada di sekitar.'],
                        ['icon' => 'water', 'title' => 'Air', 'text' => 'Sumber air tersedia.'],
                    ]]),
                    self::b('gallery', ['heading' => 'Foto lokasi', 'images' => []]),
                    self::b('location', ['heading' => 'Lokasi', 'address' => 'Alamat lengkap kavling', 'nearby' => [['place' => 'Jalan raya', 'distance' => '2 menit'], ['place' => 'Pusat kota', 'distance' => '20 menit']]]),
                    self::b('faq', ['heading' => 'Pertanyaan umum', 'items' => [
                        ['q' => 'Apakah kavling bisa dicicil?', 'a' => 'Pilihan pembayaran dijelaskan oleh tim kami saat Anda menghubungi.'],
                        ['q' => 'Kapan boleh membangun?', 'a' => 'Ketentuan membangun mengikuti izin dan aturan kawasan. Tim kami akan menjelaskannya.'],
                    ]]),
                    $form, $cta,
                ],
            ],

            'second' => [
                'name' => 'Listing rumah second', 'description' => 'Satu unit: foto besar, harga jelas, rincian, dan tombol hubungi.',
                'color' => '#dc2626', 'font' => 'modern', 'meta' => 'Rumah dijual dengan foto lengkap dan harga jelas. Hubungi untuk jadwal melihat rumah.',
                'blocks' => [
                    self::b('hero', ['headline' => 'Rumah dijual: terawat dan siap huni', 'subheadline' => 'Lokasi nyaman, lingkungan aman. Hubungi untuk jadwal melihat rumah.', 'cta_label' => 'Jadwalkan lihat rumah']),
                    self::b('price', ['label' => 'Harga', 'price' => 'Rp 000 juta', 'installment' => '', 'note' => 'Harga dapat dinegosiasikan.']),
                    self::b('gallery', ['heading' => 'Foto rumah', 'images' => []]),
                    self::b('details', ['heading' => 'Rincian rumah', 'rows' => [
                        ['label' => 'Luas tanah', 'value' => '000 m²'], ['label' => 'Luas bangunan', 'value' => '000 m²'],
                        ['label' => 'Kamar tidur', 'value' => '3'], ['label' => 'Kamar mandi', 'value' => '2'],
                        ['label' => 'Listrik', 'value' => '2200 watt'], ['label' => 'Sertifikat', 'value' => 'SHM'],
                    ]]),
                    self::b('text', ['heading' => 'Tentang rumah ini', 'html' => '<p>Tulis kondisi rumah, alasan dijual, dan hal yang membuat rumah ini menarik.</p>']),
                    self::b('location', ['heading' => 'Lokasi', 'address' => 'Alamat atau patokan lokasi', 'nearby' => []]),
                    $form, $cta,
                ],
            ],
        ];
    }
}
