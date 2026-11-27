<?php

// Bab pembuka: menu kiri, "Pengaturan & Alat", dan "Profil agensi". Format: docs/tutorial.md.
return [
    'title' => 'Menu, Pengaturan & Alat, dan Profil agensi',
    'group' => 'mulai',
    'order' => 5,
    'menu' => 'Menu kiri, Pengaturan & Alat, dan menu akun (kanan atas) > Profil agensi',
    'intro' => '<p>Menu kiri hanya berisi menu kerja harian: Dasbor, Pipeline, Lead, Inbox, Pengiriman (toko), Tugas, Halaman landing, '
        .'Formulir, {katalog}, Laporan, dan Tutorial. Pengaturan dan alat lain dikumpulkan di <b>Pengaturan & Alat</b>, dan pengaturan '
        .'akun agensi di <b>Profil agensi</b>. Setiap orang hanya melihat menu dan kartu yang boleh dibukanya.</p>',
    'steps' => [
        [
            'text' => 'Klik <b>Pengaturan & Alat</b> di akhir menu kiri. Isinya kartu berkelompok: <b>AI Asisten</b>, <b>Penjualan & otomatisasi</b>, '
                .'<b>Saluran & iklan</b>, <b>Pembayaran & pengiriman</b>, dan <b>Media</b>. Angka di menu adalah jumlah hal yang menunggu '
                .'ditinjau di dalamnya, misalnya Usulan Q&A; angka yang sama tampil di kartunya.',
            'alt' => 'Halaman Pengaturan & Alat dengan akses cepat, kotak cari, dan kartu berkelompok',
            'shot' => ['page' => 'pengaturan-alat', 'highlight' => ['.fi-sidebar-item-btn:has-text("Pengaturan & Alat")', '.st-group >> nth=0'],
                'mobile' => true, 'mobile_highlight' => ['.st-quick', '.st-group >> nth=0']],
        ],
        [
            'text' => 'Ketik di kotak cari untuk menyaring kartu, misalnya <b>ongkir</b>, <b>rekening</b>, <b>pixel</b>, atau <b>follow up</b>. '
                .'<b>Akses cepat</b> di atasnya langsung membuka Koneksi WhatsApp, Mode AI, dan Pustaka media.',
            'alt' => 'Kotak cari Pengaturan & Alat berisi kata ongkir dan kartu Pengaturan pengiriman yang ditemukan',
            'shot' => ['page' => 'pengaturan-alat', 'steps' => [['fill' => ['.st-search', 'ongkir']], ['wait' => 300]],
                'highlight' => ['.st-search', '.st-card:visible >> nth=0']],
        ],
        [
            'text' => 'Di halaman yang dibuka dari kartu, <b>Pengaturan & Alat</b> tetap tersorot di menu kiri, dan tautan di atas judul membawa '
                .'Anda kembali ke daftar kartu.',
            'alt' => 'Halaman Mode AI dengan tautan Pengaturan & Alat di atas judul dan menu Pengaturan & Alat yang tersorot',
            'shot' => ['page' => 'ai-modes', 'highlight' => ['.fi-breadcrumbs', '.fi-sidebar-item-btn:has-text("Pengaturan & Alat")']],
        ],
        [
            'text' => 'Pengaturan akun agensi ada di <b>Profil agensi</b>: klik foto atau inisial Anda di kanan atas, lalu <b>Profil agensi</b>. '
                .'<b>Profil saya</b> (nama, email, dan kata sandi Anda sendiri) tetap terpisah di menu yang sama.',
            'alt' => 'Menu akun di kanan atas dengan pilihan Profil saya dan Profil agensi',
            'shot' => ['page' => '', 'steps' => [['click' => '.fi-user-menu-trigger'], ['waitFor' => '.fi-dropdown-panel:visible']],
                'highlight' => ['.fi-dropdown-panel:visible a:has-text("Profil agensi")'], 'mobile' => true],
        ],
        [
            'text' => 'Tab di atas halaman Profil agensi: <b>Profil</b>, <b>Tim</b>, <b>Langganan</b>, <b>Notifikasi</b>, <b>Laporan harian</b>, '
                .'dan <b>Data & privasi</b>. Tab yang tidak boleh Anda buka tidak tampil; agen, misalnya, hanya melihat Notifikasi dan '
                .'Laporan harian. Di HP, tab menjadi satu tombol pilihan.',
            'alt' => 'Halaman Profil agensi dengan tab Profil, Tim, Langganan, Notifikasi, Laporan harian, dan Data & privasi',
            'shot' => ['page' => 'profil-agensi', 'highlight' => ['.fi-page-sub-navigation-tabs'],
                'mobile' => true, 'mobile_highlight' => ['.fi-page-sub-navigation-dropdown']],
        ],
    ],
    'tips' => [
        '<b>Impor lead</b> kini tombol di halaman <b>Lead</b>, di samping tombol tambah lead. Untuk agensi properti, <b>AI dan Survei</b> menjadi tab di halaman <b>Laporan</b>.',
        'Alamat halaman tidak berubah, jadi tautan dan markah yang sudah Anda simpan tetap bekerja.',
    ],
];
