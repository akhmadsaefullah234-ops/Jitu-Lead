<?php

use App\Filament\Resources\LandingPages\LandingPageResource;

return [
    'title' => 'Halaman landing',
    'group' => 'lead',
    'order' => 30,
    'leader' => true,
    'menu' => 'Halaman landing',
    'intro' => '<p>Halaman penjualan yang disusun dari bagian-bagian. Halaman terbit di /p/{akun}/{halaman} dan bisa dipasang sebagai tujuan iklan.</p>',
    'pages' => [LandingPageResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>Halaman landing</b>, klik <b>Buat halaman</b>, pilih template (misalnya Produk dengan varian warna), isi Judul halaman dan Alamat halaman, lalu klik <b>Buat</b>. Halaman baru berstatus Draf.',
            'alt' => 'Halaman Buat Halaman Landing dengan daftar template',
            'shot' => ['page' => 'landing-pages/create', 'steps' => [['click' => 'label:has-text("Produk dengan varian warna")']],
                'highlight' => ['label:has-text("Produk dengan varian warna")', '.fi-fo-field:has-text("Judul halaman")']],
        ],
        [
            'text' => 'Di tab <b>Isi halaman</b>, ganti isi contoh. Setiap bagian bisa diurutkan, digandakan, disembunyikan, atau dihapus. Klik <b>Tambah bagian</b> untuk bagian lain, misalnya galeri foto, GIF animasi, spesifikasi, ulasan, tanya jawab, video YouTube, hitung mundur promo, formulir pendaftaran, atau teks bebas.',
            'alt' => 'Tab Isi halaman dengan daftar bagian halaman landing',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['waitFor' => '[role=tab]:has-text("Isi halaman")']],
                'highlight' => ['[role=tab]:has-text("Isi halaman")', '.fi-fo-builder-item >> nth=0']],
        ],
        [
            'text' => 'Pada bagian <b>Formulir pendaftaran</b>, pilih formulir pemesanan Anda.',
        ],
        [
            'text' => 'Di tab <b>Pengaturan</b>, ubah <b>Status</b> ke <b>Terbit (tampil untuk umum)</b> saat halaman siap. Di tab ini juga ada nomor WhatsApp tujuan (kosong berarti nomor akun) dan {item} yang dipromosikan.',
            'alt' => 'Tab Pengaturan halaman landing dengan pilihan Status dan nomor WhatsApp tujuan',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Pengaturan")']],
                'highlight' => ['[role=tab]:has-text("Pengaturan")', '.fi-fo-field:has-text("Terbit (tampil untuk umum)")']],
        ],
        [
            'text' => 'Di tab <b>Tampilan</b>, pilih warna utama, jenis huruf, dan logo. Bagian <b>Jarak antar bagian</b> mengatur jarak Rapat, Normal, atau Lega. Panel gaya tiap bagian mengatur jarak atas dan bawah bagian itu sendiri.',
            'alt' => 'Tab Tampilan dengan warna, huruf, dan jarak antar bagian',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Tampilan")']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Jarak antar bagian"))', '[role=tab]:has-text("Tampilan")']],
        ],
        [
            'text' => 'Cek hasilnya di pratinjau samping (<b>HP</b>, <b>Tablet</b>, <b>Desktop</b>). Tombol <b>Pratinjau di tab baru</b> memberi tautan yang berlaku 30 menit, juga untuk halaman yang masih draf. Tab <b>Pixel & SEO</b> berisi judul dan deskripsi untuk Google, gambar pratinjau tautan, dan event pixel.',
            'alt' => 'Tombol Pratinjau di tab baru dan pratinjau HP, Tablet, Desktop di halaman landing',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['waitFor' => 'a:has-text("Pratinjau di tab baru")']],
                'highlight' => ['a:has-text("Pratinjau di tab baru")', 'button:text-is("HP")']],
        ],
    ],
    'tips' => ['Unggah foto asli {item}. Foto dikecilkan otomatis; batas unggah 8 MB, GIF 9 MB.'],
    'perhatian' => ['Hanya halaman berstatus Terbit yang bisa dibuka umum. Tulis spesifikasi yang benar (bahan, ukuran, warna, retur), dan isi Tanya jawab agar pertanyaan umum terjawab sebelum pembeli chat.'],
];
