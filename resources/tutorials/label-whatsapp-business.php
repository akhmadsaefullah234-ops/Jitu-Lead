<?php

return [
    'title' => 'Label WhatsApp Business otomatis (uji coba)',
    'group' => 'iklan',
    'order' => 20,
    'admin' => true,
    'menu' => 'Pengaturan > Koneksi WhatsApp > klik nomor gateway > Label WhatsApp Business',
    'intro' => '<p>Memasang label WhatsApp Business (misalnya Prospek, Order baru, Lunas) di chat lead secara otomatis saat lead pindah tahap atau ditandai Hot, sebagai sinyal untuk iklan Klik ke WhatsApp. Hanya untuk nomor gateway yang memakai akun WhatsApp Business.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Koneksi WhatsApp</b>, klik nomor gateway, lalu buka bagian <b>Label WhatsApp Business (uji coba)</b>. Klik <b>Muat label dari WhatsApp</b> agar sistem mengenali label di akun Anda. Label yang belum ada dibuat sekali di HP, lalu klik tombol itu lagi.',
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
    'tips' => ['Pantau <b>Ringkasan 30 hari</b> di bagian yang sama dan cocokkan dengan hasil di Ads Manager.'],
    'perhatian' => ['Fitur ini masih uji coba. Bila angka di Ads Manager tidak ikut bergerak, matikan saja.'],
];
