<?php

return [
    'title' => 'Resi otomatis',
    'group' => 'pengiriman',
    'order' => 40,
    'industries' => ['retail'],
    'admin' => true,
    'menu' => 'Pengaturan > Pengiriman > Resi otomatis',
    'intro' => '<p>Mati secara bawaan. Bila aktif, lead yang masuk tahap pemicu dan datanya lengkap dibuatkan resi sendiri setelah jeda. Lead yang datanya belum lengkap tetap dibuat manual, dengan alasannya tercatat di lead.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Pengaturan > Pengiriman</b>, bagian <b>Resi otomatis</b>, nyalakan <b>Aktifkan resi otomatis</b>. Pilih <b>Tahap pemicu</b> (bawaan Dikemas & dikirim), <b>Jeda sebelum resi dibuat</b> (bawaan 30 menit), <b>Batas resi otomatis per hari</b> (bawaan 50), dan <b>Batas nilai COD per paket</b> (bawaan Rp1.000.000). Klik <b>Simpan pengiriman otomatis</b>.',
            'alt' => 'Bagian Resi otomatis dengan tahap pemicu, jeda, batas harian, dan batas COD',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Resi otomatis"))'], ['click' => '.fi-fo-field:has-text("Aktifkan resi otomatis") button[role=switch]'], ['wait' => 600],
                ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Resi otomatis"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Resi otomatis"))']],
        ],
        [
            'text' => 'Syarat resi otomatis: cara bayar COD, atau transfer yang sudah dicek lunas; alamat dengan RT/RW (atau nomor rumah atau patokan), kelurahan, kecamatan, kota, provinsi, dan kode pos yang cocok dengan lokasi Everpro; produk, varian, jumlah, dan berat terisi; kurir tersedia; belum ada kiriman aktif; tidak dicurigai spam. Kolom <b>Resi</b> dan saringan <b>Kesiapan resi</b> di daftar Lead menunjukkan lead mana yang Siap resi atau Perlu dilengkapi.',
            'alt' => 'Saringan Kesiapan resi di daftar Lead',
            'shot' => ['page' => 'leads', 'steps' => [['click' => '.fi-ta-filters-dropdown .fi-dropdown-trigger button, .fi-ta-filters-dropdown > div > button'], ['waitFor' => '.fi-dropdown-panel:visible']],
                'highlight' => ['.fi-dropdown-panel:visible .fi-fo-field:has-text("Kesiapan resi")']],
        ],
    ],
    'tips' => [
        'Selama jeda, lead dan Inbox menampilkan tombol "Resi otomatis dalam N menit · Batalkan" untuk membatalkan atau mengoreksi dulu.',
        'Setiap pagi pukul 07.00, admin dan team leader mendapat ringkasan pengiriman kemarin.',
    ],
    'perhatian' => ['Kesalahan dari Everpro tidak dicoba ulang otomatis: lead bertanda "Resi gagal dibuat". Perbaiki penyebabnya, lalu klik <b>Coba lagi kiriman</b>.'],
];
