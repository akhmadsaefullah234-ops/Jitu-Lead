<?php

return [
    'title' => 'Label WhatsApp Business otomatis (uji coba)',
    'group' => 'iklan',
    'order' => 20,
    'admin' => true,
    'menu' => 'Pengaturan & Alat > Koneksi WhatsApp > klik nomor gateway > Label WhatsApp Business',
    'intro' => '<p>Memasang label WhatsApp Business (misalnya Prospek, Order baru, Lunas) di chat lead secara otomatis saat lead pindah tahap atau ditandai Hot, sebagai sinyal untuk iklan Klik ke WhatsApp. Hanya untuk nomor gateway yang memakai akun WhatsApp Business.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Pengaturan & Alat > Koneksi WhatsApp</b>, klik nomor gateway, lalu buka bagian <b>Label WhatsApp Business (uji coba)</b>. Klik <b>Muat label dari WhatsApp</b> agar sistem mengenali label di akun Anda. Bila label belum ada, ikuti petunjuk di layar: di WhatsApp Bisnis, label standar ada di bagian Saran dan baru menjadi Daftar Anda setelah dipakai sekali. Buka satu chat UJI (bukan pelanggan), centang Prospek, Order baru, dan Lunas, pilih Selesai, lalu klik tombol itu lagi.',
            'alt' => 'Bagian Label WhatsApp Business dengan tombol Muat label dari WhatsApp',
            'shot' => ['page' => 'wa-channels', 'steps' => [['click' => '.fi-ta-row >> nth=0 >> a.fi-ta-col >> nth=0'], ['waitFor' => '.fi-section-header-heading:has-text("Label WhatsApp Business")'],
                ['click' => '.fi-section-header-heading:has-text("Label WhatsApp Business")'], ['wait' => 500], ['scroll' => '.fi-section:has(.fi-section-header-heading:has-text("Label WhatsApp Business"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("Label WhatsApp Business")) .fi-section-header', 'button:has-text("Muat label dari WhatsApp"), a:has-text("Muat label dari WhatsApp")']],
        ],
        [
            'text' => 'Nyalakan <b>Aktifkan label otomatis</b> (bawaan mati). <b>Hanya chat dari iklan</b> (bawaan nyala) membatasi pada lead dari iklan Klik ke WhatsApp. Untuk tiap label (calon pembeli, pesanan, lunas), isi nama labelnya di WhatsApp, lalu pemicunya: <b>Saat lead ditandai Hot</b> dan/atau <b>Saat masuk tahap</b>. Klik <b>Simpan</b>.',
            'alt' => 'Sakelar Aktifkan label otomatis dan aturan label per tahap',
            'shot' => ['page' => 'wa-channels', 'steps' => [['click' => '.fi-ta-row >> nth=0 >> a.fi-ta-col >> nth=0'], ['waitFor' => '.fi-section-header-heading:has-text("Label WhatsApp Business")'],
                ['click' => '.fi-section-header-heading:has-text("Label WhatsApp Business")'], ['wait' => 500], ['scroll' => '.fi-fo-field:has-text("Aktifkan label otomatis")']],
                'highlight' => ['.fi-fo-field:has-text("Aktifkan label otomatis")', 'fieldset:has-text("Label calon pembeli")']],
        ],
    ],
    'tips' => [
        'Tombol <b>Pakai bawaan Jitu</b> menyalakan label otomatis untuk chat dari iklan dengan aturan bawaan dalam satu klik. Properti: Prospek saat Terkualifikasi, Hot, atau Jadwal survei; Order baru saat Booking; Lunas saat Closing.',
        'Label yang tidak dibaca Meta sebagai sinyal diberi tanda <b>bukan sinyal Meta</b>. Hanya Prospek, Order baru, dan Lunas yang dihitung.',
        'Pantau <b>Ringkasan 30 hari</b> di bagian yang sama dan cocokkan dengan hasil di Ads Manager.',
    ],
    'perhatian' => ['Fitur ini masih uji coba. Bila angka di Ads Manager tidak ikut bergerak, matikan saja.'],
];
