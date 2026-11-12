<?php

return [
    'title' => 'Koneksi WhatsApp',
    'group' => 'percakapan',
    'order' => 5,
    'admin' => true,
    'menu' => 'Pengaturan > Koneksi WhatsApp',
    'intro' => '<p>Ada dua jenis nomor. <b>WhatsApp Business API resmi</b>: menerima chat dari iklan Klik ke WhatsApp lewat Meta. <b>Gateway (scan QR)</b>: nomor WhatsApp biasa atau WhatsApp Business yang ditautkan dengan memindai QR, dipakai untuk chat, sambutan, follow-up, dan pesan status kiriman.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Koneksi WhatsApp</b>. Tiap nomor menampilkan jenis, mode AI, dan statusnya. Di ujung kanan baris ada tombol <b>Tes koneksi</b>, <b>Scan QR</b> (atau <b>Scan QR ulang</b> bila terputus), dan <b>Putuskan</b>. Klik <b>Buat nomor WhatsApp</b> untuk menambah.',
            'alt' => 'Daftar Koneksi WhatsApp dengan tombol Tes koneksi, Scan QR, dan Putuskan',
            'shot' => ['page' => 'wa-channels', 'highlight' => ['.fi-header button:has-text("Buat nomor WhatsApp"), .fi-header a:has-text("Buat nomor WhatsApp")', '.fi-ta-row >> nth=0']],
        ],
        [
            'text' => 'Di bagian <b>Nomor</b>, pilih <b>Jenis</b> (tidak bisa diubah setelah disimpan), beri nama, isi nomor, dan <b>Urutan pakai</b>: untuk gateway, nomor dengan urutan terkecil dipakai dulu, sisanya cadangan. Di <b>Asisten AI</b>, pilih siapa yang membalas chat di nomor ini.',
            'alt' => 'Bagian Nomor dan Asisten AI di halaman nomor WhatsApp',
            'shot' => ['page' => 'wa-channels', 'steps' => [['click' => '.fi-ta-row >> nth=0 >> a.fi-ta-col >> nth=0'], ['waitFor' => '.fi-section-header-heading:text-is("Nomor")']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Nomor"))', '.fi-section:has(.fi-section-header-heading:text-is("Asisten AI"))']],
        ],
        [
            'text' => 'Untuk gateway, simpan lalu klik <b>Scan QR</b> di daftar. Di HP buka WhatsApp > <b>Perangkat tertaut</b> > <b>Tautkan perangkat</b> dan pindai QR-nya. Isi juga <b>Pesan perkenalan</b>: pesan yang dikirim lebih dulu saat nomor ini pertama kali menulis ke pembeli (sambutan formulir tidak memakainya).',
            'alt' => 'Bagian Pesan perkenalan untuk nomor gateway',
            'shot' => ['page' => 'wa-channels', 'steps' => [['click' => '.fi-ta-row >> nth=0 >> a.fi-ta-col >> nth=0'], ['waitFor' => '.fi-section-header-heading:text-is("Pesan perkenalan")']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pesan perkenalan"))']],
        ],
    ],
    'tips' => ['Untuk nomor API resmi, buka bagian <b>Panduan: menyambungkan WhatsApp Business API resmi</b> di halaman nomor dan ikuti langkahnya. Verify token webhook sudah dibuatkan otomatis.'],
    'perhatian' => ['Access token dan kunci lain tersimpan terenkripsi dan tidak ditampilkan lagi. Bila nomor gateway terputus, Dasbor menampilkannya; klik Scan QR ulang. Jangan keluarkan perangkat tertaut dari HP.'],
];
