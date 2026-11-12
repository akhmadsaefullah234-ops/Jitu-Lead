<?php

return [
    'title' => 'Halaman landing properti',
    'group' => 'lead',
    'order' => 29,
    'industries' => ['properti'],
    'leader' => true,
    'menu' => 'Halaman landing',
    'intro' => '<p>Halaman penjualan untuk satu proyek atau listing, disusun dari bagian-bagian: harga, tipe unit, fasilitas, lokasi, simulasi KPR, legalitas, dan formulir. Halaman terbit di /p/{akun}/{halaman} dan bisa dipasang sebagai tujuan iklan.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Halaman landing</b>, klik <b>Buat halaman</b>, lalu pilih template: <b>Rumah KPR / subsidi</b>, <b>Cluster / perumahan premium</b>, <b>Villa / properti investasi</b>, <b>Kavling / tanah</b>, <b>Listing rumah second</b>, <b>Properti – konversi tinggi</b> (versi formulir atau WhatsApp), <b>Layanan – konversi tinggi</b>, atau <b>Halaman kosong</b>. Isi Judul halaman dan Alamat halaman, lalu klik <b>Buat</b>. Halaman baru berstatus Draf.',
            'alt' => 'Halaman Buat Halaman Landing dengan template properti, template Rumah KPR / subsidi disorot',
            'shot' => ['page' => 'landing-pages/create', 'steps' => [['click' => 'label:has-text("Rumah KPR / subsidi")']],
                'highlight' => ['label:has-text("Rumah KPR / subsidi")', '.fi-fo-field:has-text("Judul halaman")']],
        ],
        [
            'text' => 'Di tab <b>Isi halaman</b>, ganti isi contoh di setiap bagian. Bagian khusus properti: <b>Harga mulai dari + cicilan</b> dan <b>Simulasi KPR</b> (kalkulator cicilan dengan harga, uang muka, tenor, dan bunga awal). Bagian yang sering dipakai properti: <b>Tipe unit</b>, <b>Fasilitas</b>, <b>Lokasi, peta & akses terdekat</b> (sematan Google Maps), dan <b>Legalitas</b>.',
            'alt' => 'Bagian Simulasi KPR di tab Isi halaman dengan harga properti awal dan bunga awal',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Cluster Contoh Asri - KPR ringan"'], ['waitFor' => '[role=tab]:has-text("Isi halaman")'], ['click' => 'text="Tampilkan semua"'], ['wait' => 800],
                ['scroll' => '.fi-fo-field:has-text("Harga properti awal")']],
                'highlight' => ['.fi-fo-field:has(> * :text-is("Harga properti awal (Rupiah, angka saja)")), .fi-fo-field:has-text("Harga properti awal") >> nth=-1', '.fi-fo-field:has-text("Bunga awal") >> nth=-1']],
        ],
        [
            'text' => 'Klik <b>Tambah bagian</b> untuk bagian lain, misalnya galeri foto, video YouTube, testimoni, tanya jawab, hitung mundur promo, atau formulir pendaftaran. Pada bagian <b>Formulir pendaftaran</b>, pilih formulir properti Anda (lihat bab Formulir properti).',
        ],
        [
            'text' => 'Di tab <b>Pengaturan</b>, pilih <b>properti yang dipromosikan</b> dan ubah <b>Status</b> ke <b>Terbit (tampil untuk umum)</b> saat halaman siap. Tab <b>Tampilan</b>, <b>Pixel & SEO</b>, dan <b>Uji A/B</b> sama dengan halaman landing biasa (lihat bab Halaman landing).',
            'alt' => 'Tab Pengaturan halaman landing properti dengan pilihan Status',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Cluster Contoh Asri - KPR ringan"'], ['click' => '[role=tab]:has-text("Pengaturan")']],
                'highlight' => ['[role=tab]:has-text("Pengaturan")', '.fi-fo-field:has-text("Terbit (tampil untuk umum)")']],
        ],
        [
            'text' => 'Buka halamannya untuk memeriksa. Pengunjung bisa menghitung cicilan sendiri di bagian Simulasi KPR; angka yang tampil adalah perkiraan, dengan catatan bahwa itu bukan penawaran bank.',
            'alt' => 'Halaman landing properti terbit dengan kalkulator Simulasi KPR, tampilan desktop dan HP',
            'shot' => ['page' => '/p/agensi-demo/cluster-contoh-asri', 'steps' => [['scroll' => '[data-kpr]']], 'highlight' => ['[data-kpr]'], 'mobile' => true],
        ],
    ],
    'tips' => ['Pakai foto dan denah asli proyek, dan tulis legalitas apa adanya (SHM, PBG, atau masih dalam proses).'],
    'perhatian' => ['Jangan menjanjikan angka cicilan, kenaikan harga, atau imbal hasil sewa yang tidak pasti. Template Villa sengaja tidak memuat janji angka; simulasi KPR selalu disertai catatan bahwa angkanya perkiraan.'],
];
