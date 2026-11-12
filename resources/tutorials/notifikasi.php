<?php

return [
    'title' => 'Notifikasi',
    'group' => 'akun',
    'order' => 30,
    'menu' => 'Pengaturan > Notifikasi',
    'intro' => '<p>Notifikasi di HP dan komputer untuk lead baru (dari formulir, halaman landing, dan WhatsApp) dan pesan WhatsApp masuk. Admin menerima semua lead baru dan pesan lead tanpa pemilik; agen menerima lead dan pesan miliknya.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Notifikasi</b> dari alamat https JITU LEAD, klik <b>Aktifkan di perangkat ini</b>, dan izinkan di browser. Klik <b>Kirim notifikasi tes</b> untuk memastikan. Di <b>Beri tahu saya tentang</b>, pilih Lead baru dan/atau Pesan WhatsApp masuk. Ulangi di tiap perangkat; semua perangkat tampil di <b>Perangkat yang terdaftar</b>.',
            'alt' => 'Halaman Notifikasi di HP dan komputer',
            'shot' => ['page' => 'notifications', 'highlight' => ['button:has-text("Aktifkan di perangkat ini")']],
        ],
    ],
    'tips' => ['Di iPhone atau iPad (iOS 16.4 ke atas): buka JITU LEAD di Safari, ketuk Bagikan, pilih <b>Tambahkan ke Layar Utama</b>, buka dari ikon itu, lalu aktifkan notifikasi di halaman ini.'],
    'perhatian' => ['Notifikasi tetap aktif di perangkat sampai dimatikan. Klik <b>Matikan di perangkat ini</b> di komputer bersama sebelum keluar.'],
];
