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

    /**
     * The templates an agency of this industry can start from (null: all); templates without an industry are for everyone.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public static function options(?string $industry = null): array
    {
        $templates = array_filter(self::all(), fn ($t) => $industry === null || ($t['industry'] ?? $industry) === $industry);

        return array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description']], $templates);
    }

    /** A shop template built around one product, whose product and order form can be picked when the page is made. */
    public static function sellsProduct(?string $key): bool
    {
        return ! empty(self::all()[$key ?? '']['product']);
    }

    /** @return array{name: string, description: string, color: string, font: string, meta: string, blocks: list<array<string, mixed>>, page_events?: array<string, string>, navigation?: array<string, mixed>} */
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
        $perks = self::b('shop_perks', ['heading' => 'Belanja aman di sini', 'items' => [
            ['icon' => 'truck', 'title' => 'Gratis / diskon ongkir', 'text' => 'Tulis syarat ongkir Anda, misalnya untuk pembelian minimal tertentu.'],
            ['icon' => 'cash', 'title' => 'Bisa COD', 'text' => 'Bayar di tempat saat paket sampai.'],
            ['icon' => 'return', 'title' => 'Retur dengan video unboxing', 'text' => 'Rekam video saat membuka paket agar klaim retur mudah diproses.'],
            ['icon' => 'box', 'title' => 'Dikirim dengan JNE', 'text' => 'Nomor resi dikirim lewat WhatsApp.'],
            ['icon' => 'clock', 'title' => 'Jam layanan', 'text' => 'Setiap hari 08.00 - 21.00 WIB.'],
        ]]);
        $specs = self::b('specs', ['heading' => 'Spesifikasi', 'rows' => [
            ['label' => 'Ukuran', 'value' => 'Tulis ukuran produk'], ['label' => 'Bahan', 'value' => 'Tulis bahan produk'],
            ['label' => 'Kapasitas', 'value' => 'Tulis kapasitas atau berat'], ['label' => 'Isi paket', 'value' => 'Tulis isi paket'],
        ]]);
        $reviews = self::b('reviews', ['heading' => 'Ulasan pembeli', 'items' => [
            ['name' => 'Nama pembeli', 'rating' => 5, 'quote' => 'Ganti dengan ulasan asli pembeli Anda.', 'photo' => null],
        ]]);
        $shopFaq = self::b('faq', ['heading' => 'Tanya jawab', 'items' => [
            ['q' => 'Apakah bisa COD?', 'a' => 'Bisa, untuk wilayah yang dijangkau kurir. Pilih COD di formulir order.'],
            ['q' => 'Berapa lama pengiriman?', 'a' => 'Biasanya 2-5 hari kerja untuk Pulau Jawa dan lebih lama untuk luar Jawa.'],
            ['q' => 'Bagaimana jika barang rusak atau tidak sesuai?', 'a' => 'Kirim video unboxing ke admin, kami bantu proses retur atau tukar.'],
        ]]);
        $orderForm = self::b('form', ['heading' => 'Form order', 'intro' => 'Isi data pesanan, admin kami langsung konfirmasi lewat WhatsApp.', 'button' => 'Pesan Sekarang']);
        $cta = self::b('cta', ['heading' => 'Tertarik? Tanyakan langsung', 'text' => 'Tim kami siap menjawab pertanyaan Anda.', 'label' => 'Chat via WhatsApp', 'message' => 'Halo, saya tertarik dengan unit yang ditawarkan.']);

        return [
            self::BLANK => [
                'name' => 'Halaman kosong', 'description' => 'Mulai dari nol: hanya judul dan formulir pendaftaran.',
                'color' => '#dc2626', 'font' => 'modern', 'meta' => '',
                'blocks' => [self::b('hero', ['headline' => 'Judul halaman Anda', 'subheadline' => 'Tulis kalimat pendukung di sini.', 'cta_label' => 'Daftar sekarang']), $form],
            ],

            'kpr' => [
                'industry' => 'properti',
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
                'industry' => 'properti',
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
                'industry' => 'properti',
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
                'industry' => 'properti',
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
                'industry' => 'properti',
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

            'produk_promo' => [
                'industry' => 'retail', 'product' => true,
                'name' => 'Produk tunggal – promo', 'description' => 'Foto produk, harga coret dan harga promo, keunggulan belanja, spesifikasi, ulasan, tanya jawab, dan formulir order.',
                'color' => '#ea580c', 'font' => 'ramah', 'meta' => 'Promo spesial: pesan sekarang, bisa COD dan gratis ongkir untuk wilayah tertentu.',
                'blocks' => [
                    self::b('product', ['name' => 'Nama produk Anda', 'images' => [], 'price' => 199000, 'promo_price' => 149000, 'stock' => 'low', 'stock_note' => 'Promo terbatas',
                        'variant_label' => 'Warna', 'variants' => [], 'sizes' => [],
                        'points' => ['Tulis keunggulan utama produk', 'Bahan dan kualitas yang membuat pembeli yakin', 'Manfaat yang dirasakan pembeli'],
                        'buttons' => [['label' => 'Pesan Sekarang', 'action' => 'scroll', 'color' => 'brand', 'size' => 'lg', 'icon' => 'cart']]]),
                    $perks,
                    $specs,
                    $reviews,
                    $shopFaq,
                    $orderForm,
                ],
            ],

            'produk_varian' => [
                'industry' => 'retail', 'product' => true,
                'name' => 'Produk dengan varian warna', 'description' => 'Seperti produk tunggal, ditambah pilihan warna dengan contoh warna (swatch), galeri foto per warna, dan pilihan ukuran.',
                'color' => '#7c3aed', 'font' => 'modern', 'meta' => 'Tersedia banyak pilihan warna dan ukuran. Pesan sekarang, bisa COD.',
                'blocks' => [
                    self::b('product', ['name' => 'Nama produk Anda', 'images' => [], 'price' => 179000, 'promo_price' => 139000, 'stock' => 'ready', 'stock_note' => '',
                        'variant_label' => 'Warna', 'variants' => [
                            ['name' => 'Hitam', 'swatch' => '#111827', 'images' => []],
                            ['name' => 'Putih', 'swatch' => '#f9fafb', 'images' => []],
                            ['name' => 'Navy', 'swatch' => '#1e3a8a', 'images' => []],
                        ], 'sizes' => ['S', 'M', 'L', 'XL'],
                        'points' => ['Unggah foto tiap warna di bagian Varian', 'Tulis bahan dan ukuran yang tersedia', 'Sebutkan bonus atau garansi bila ada'],
                        'buttons' => [['label' => 'Pesan Sekarang', 'action' => 'scroll', 'color' => 'brand', 'size' => 'lg', 'icon' => 'cart']]]),
                    $perks,
                    $specs,
                    $reviews,
                    $shopFaq,
                    $orderForm,
                ],
            ],

            'terima_kasih' => [
                'industry' => 'retail',
                'name' => 'Halaman terima kasih', 'description' => 'Untuk dibuka setelah order: ucapan, langkah berikutnya, satu tombol WhatsApp, dan event pixel Lead saat dibuka.',
                'color' => '#16a34a', 'font' => 'ramah', 'meta' => 'Terima kasih, pesanan Anda sudah kami terima.',
                'page_events' => ['meta' => 'Lead', 'tiktok' => 'Lead', 'google' => 'generate_lead'],
                'navigation' => ['auto_form' => false],
                'blocks' => [
                    self::b('text', ['heading' => 'Terima kasih, pesanan Anda sudah kami terima!', 'html' => '<p>Admin kami akan segera menghubungi Anda lewat WhatsApp untuk konfirmasi pesanan.</p>']),
                    self::b('highlights', ['heading' => 'Langkah berikutnya', 'items' => [
                        ['title' => '1. Konfirmasi pesanan', 'text' => 'Admin mengecek varian, jumlah, dan alamat kirim Anda.'],
                        ['title' => '2. Total dan ongkir', 'text' => 'Anda menerima total harga termasuk ongkir lewat WhatsApp.'],
                        ['title' => '3. Pembayaran dan pengiriman', 'text' => 'Transfer sesuai total, atau bayar di tempat bila memilih COD. Nomor resi kami kirim setelah paket dikirim.'],
                    ]]),
                    self::b('buttons', ['heading' => 'Belum dihubungi?', 'text' => 'Chat admin kami langsung.', 'align' => 'center',
                        'buttons' => [['label' => 'Chat admin via WhatsApp', 'action' => 'whatsapp', 'wa_target' => 'rotate', 'wa_message' => 'Halo admin, saya baru order {produk}. Mohon dicek ya.', 'color' => 'wa', 'size' => 'lg', 'icon' => 'whatsapp']]]),
                ],
            ],

            'konversi_produk' => self::conversion('retail', 'Produk – konversi tinggi', 'Satu tujuan: pembeli mengisi formulir Order produk. Hero, masalah & solusi, manfaat, bukti sosial, tanya jawab, penutup.', true, 'shop', true),
            'konversi_produk_wa' => self::conversion('retail', 'Produk – langsung WhatsApp', 'Struktur konversi yang sama, semua tombol langsung membuka WhatsApp admin dengan pesan otomatis.', false, 'shop'),
            'konversi_properti' => self::conversion('properti', 'Properti – konversi tinggi (formulir)', 'Satu tujuan: calon pembeli mengisi formulir. Hero, masalah & solusi, manfaat, fasilitas, bukti sosial, tanya jawab, penutup.', true, 'property'),
            'konversi_properti_wa' => self::conversion('properti', 'Properti – konversi tinggi (WhatsApp)', 'Struktur konversi yang sama, semua tombol langsung membuka WhatsApp marketing bergiliran.', false, 'property'),
            'konversi_layanan' => self::conversion(null, 'Layanan – konversi tinggi', 'Untuk jasa: hero, masalah & solusi, manfaat, bukti sosial, tanya jawab, penutup, dan formulir.', true, 'service'),
        ];
    }

    /**
     * A page built to convert, with one goal (the form or WhatsApp): Hero, Masalah & solusi, Manfaat, Bukti sosial,
     * Tanya jawab, Penutup. The sample text is bracketed placeholders, never claims or numbers.
     *
     * @param  'shop'|'property'|'service'  $kind
     * @return array<string, mixed>
     */
    private static function conversion(?string $industry, string $name, string $description, bool $form, string $kind, bool $product = false): array
    {
        $thing = ['shop' => '[Nama produk]', 'property' => '[Nama perumahan]', 'service' => '[Nama layanan]'][$kind];
        $cta = $form
            ? ['label' => ['shop' => 'Pesan Sekarang', 'property' => 'Minta info & harga', 'service' => 'Konsultasi gratis'][$kind], 'action' => 'scroll', 'color' => 'brand', 'size' => 'lg', 'icon' => $kind === 'shop' ? 'cart' : 'arrow']
            : ['label' => 'Chat via WhatsApp', 'action' => 'whatsapp', 'wa_target' => 'rotate', 'wa_message' => 'Halo, saya tertarik dengan {judul}. Boleh minta info lengkapnya?', 'color' => 'wa', 'size' => 'lg', 'icon' => 'whatsapp'];
        $second = $kind === 'property' && $form
            ? [['label' => 'Chat via WhatsApp', 'action' => 'whatsapp', 'wa_target' => 'rotate', 'wa_message' => 'Halo, saya tertarik dengan {judul}.', 'color' => 'outline', 'size' => 'lg', 'icon' => 'whatsapp']]
            : [];
        $assure = ['shop' => '[Gratis ongkir] • [Bisa COD] • [Retur mudah]', 'property' => '[Legalitas jelas] • [Bisa KPR] • [Survei gratis]', 'service' => '[Konsultasi gratis] • [Tanpa biaya tersembunyi]'][$kind];

        $hero = ['headline' => $thing.': [nilai utama dalam satu kalimat]', 'subheadline' => '[Untuk siapa] yang ingin [hasil yang diinginkan], tanpa [hambatan utama].',
            'visual' => $kind === 'shop' ? 'slider' : 'image', 'image' => null,
            'slider_slides' => [], 'slider_per_mobile' => 1, 'slider_per_tablet' => 1, 'slider_per_desktop' => 1, 'slider_ratio' => '1:1', 'slider_arrows' => true, 'slider_dots' => true, 'slider_loop' => true,
            'buttons' => [$form ? ['color' => 'light'] + $cta : $cta, ...$second], 'assurance' => $assure];

        $blocks = [
            self::b('hero', $hero),
            self::b('problem_solution', ['heading' => '[Masalah yang paling sering dialami calon pembeli]', 'problem_title' => 'Sering mengalami ini?', 'solution_title' => 'Dengan '.$thing,
                'problems' => [['icon' => '✕', 'text' => '[Masalah pertama]'], ['icon' => '✕', 'text' => '[Masalah kedua]'], ['icon' => '✕', 'text' => '[Masalah ketiga]']],
                'solutions' => [['icon' => '✓', 'text' => '[Solusi untuk masalah pertama]'], ['icon' => '✓', 'text' => '[Solusi untuk masalah kedua]'], ['icon' => '✓', 'text' => '[Solusi untuk masalah ketiga]']]]),
            self::b('benefits', ['heading' => 'Yang Anda dapatkan', 'intro' => '[Satu kalimat tentang hasil yang dirasakan pembeli]', 'items' => [
                ['icon_type' => 'icon', 'icon' => 'check', 'title' => '[Manfaat utama]', 'text' => '[Jelaskan apa yang dirasakan pembeli, bukan fitur teknis]'],
                ['icon_type' => 'icon', 'icon' => 'shield', 'title' => '[Manfaat kedua]', 'text' => '[Penjelasan singkat]'],
                ['icon_type' => 'icon', 'icon' => 'clock', 'title' => '[Manfaat ketiga]', 'text' => '[Penjelasan singkat]'],
            ]]),
        ];

        if ($kind === 'shop') {
            $blocks[] = self::b('icon_list', ['heading' => 'Keunggulan belanja', 'layout' => 'grid', 'cols_desktop' => 4, 'cols_mobile' => 2, 'icon_shape' => 'none', 'icon_size' => 32, 'items' => [
                ['icon_type' => 'emoji', 'emoji' => '🚚', 'title' => 'Gratis ongkir', 'text' => '[Syarat gratis ongkir]'],
                ['icon_type' => 'emoji', 'emoji' => '💵', 'title' => 'Bisa COD', 'text' => 'Bayar di tempat'],
                ['icon_type' => 'emoji', 'emoji' => '🔄', 'title' => 'Retur dengan video unboxing', 'text' => '[Syarat retur]'],
                ['icon_type' => 'emoji', 'emoji' => '⏰', 'title' => 'Admin 05.00–21.00', 'text' => '[Hari layanan]'],
            ]]);
        } elseif ($kind === 'property') {
            $blocks[] = self::b('icon_list', ['heading' => 'Fasilitas', 'layout' => 'list', 'cols_desktop' => 3, 'cols_mobile' => 1, 'icon_shape' => 'circle', 'items' => [
                ['icon_type' => 'icon', 'icon' => 'shield', 'title' => '[Keamanan]', 'text' => '[Keterangan]'],
                ['icon_type' => 'icon', 'icon' => 'tree', 'title' => '[Taman / ruang hijau]', 'text' => '[Keterangan]'],
                ['icon_type' => 'icon', 'icon' => 'road', 'title' => '[Akses jalan]', 'text' => '[Keterangan]'],
            ]]);
        }

        $blocks[] = self::b('social_proof', ['heading' => 'Kata mereka', 'testimonials' => [
            ['name' => '[Nama asli pembeli]', 'role' => '[Kota]', 'quote' => '[Ganti dengan testimoni asli, dengan izin pembeli]', 'rating' => null, 'photo' => null],
        ], 'logos' => [], 'stats' => []]);
        $blocks[] = self::b('faq', ['heading' => 'Masih ragu?', 'items' => [
            ['q' => '[Keraguan yang paling sering ditanyakan]', 'a' => '[Jawaban yang jujur dan jelas]'],
            ['q' => '[Pertanyaan tentang harga atau pembayaran]', 'a' => '[Jawaban]'],
            ['q' => '[Pertanyaan tentang garansi, retur, atau proses]', 'a' => '[Jawaban]'],
        ]]);
        $blocks[] = self::b('closing', ['heading' => '[Ajakan penutup: ulangi nilai utama]', 'text' => '[Alasan untuk bertindak sekarang, tanpa janji palsu]',
            'assurances' => ['shop' => ['[Garansi]', '[Retur mudah]', '[Bisa COD]'], 'property' => ['[Legalitas jelas]', '[Survei gratis]'], 'service' => ['[Garansi layanan]', '[Konsultasi gratis]']][$kind],
            'buttons' => [$cta]]);

        if ($form) {
            $blocks[] = self::b('form', ['heading' => ['shop' => 'Form order', 'property' => 'Dapatkan info lengkap', 'service' => 'Jadwalkan konsultasi'][$kind],
                'intro' => 'Isi data singkat ini, kami hubungi lewat WhatsApp.']);
        }

        return array_filter([
            'industry' => $industry, 'product' => $product ?: null,
            'name' => $name, 'description' => $description,
            'color' => ['shop' => '#ea580c', 'property' => '#1d4ed8', 'service' => '#0f766e'][$kind], 'font' => 'modern',
            'meta' => '[Satu kalimat ringkas tentang '.$thing.' untuk pratinjau link]',
            'navigation' => $form ? null : ['auto_form' => false],
            'blocks' => $blocks,
        ], fn ($v) => $v !== null);
    }
}
