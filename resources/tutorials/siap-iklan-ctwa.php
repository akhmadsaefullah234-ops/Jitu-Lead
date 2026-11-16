<?php

return [
    'title' => 'Siap iklan Klik ke WhatsApp',
    'group' => 'iklan',
    'order' => 30,
    'industries' => ['properti'],
    'admin' => true,
    'menu' => 'Pengaturan > Siap iklan CTWA',
    'intro' => '<p>Iklan Klik ke WhatsApp belajar dari sinyal yang agensi kirim ke Meta: event dari pipeline (Lead, QualifiedLead, Schedule, InitiateCheckout, Purchase) dan label WhatsApp Business (Prospek, Order baru, Lunas). Halaman ini memeriksa semuanya dalam satu tempat sebelum iklan dinyalakan, dan tidak mengubah apa pun.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Siap iklan CTWA</b>. Tiap baris diberi tanda <b>Siap</b>, <b>Perlu dicek</b>, <b>Belum</b>, atau <b>Cek manual</b>: Pixel dan token, event pipeline, otomasi tahap, event yang pernah terkirim, lead dari iklan, label di tiap nomor gateway, dan berbagi data WhatsApp Business ke Meta. Baris yang belum siap menyebut apa yang perlu dilakukan.',
            'alt' => 'Halaman Siap iklan CTWA dengan daftar pemeriksaan',
            'shot' => ['page' => 'siap-iklan-ctwa', 'highlight' => ['.fi-section >> nth=0']],
        ],
        [
            'text' => 'Klik <b>Uji kirim event</b>, pilih event, lalu <b>Kirim</b>. Event masuk ke tab Test Events di Events Manager bila <b>Kode uji event</b> sudah diisi di Pelacakan iklan. Di bawahnya, <b>Ringkasan 30 hari</b> menghitung event per pemicu dan label per status untuk dicocokkan dengan Ads Manager.',
            'alt' => 'Tombol Uji kirim event dan Ringkasan 30 hari',
            'shot' => ['page' => 'siap-iklan-ctwa', 'highlight' => ['.fi-header button:has-text("Uji kirim event")', '.fi-section >> nth=1']],
        ],
    ],
    'tips' => [
        'Berbagi data WhatsApp Business ke Meta tidak bisa dicek dari sini. Di HP: WhatsApp Bisnis > Alat bisnis > Iklan / Akun yang terhubung, pastikan akun Meta terhubung dan berbagi data diizinkan.',
        'Angka di Ads Manager bisa berbeda dari ringkasan karena Meta mencocokkan pengguna lebih dulu dan atribusinya terlambat beberapa hari.',
    ],
];
