<?php

return [
    'title' => 'Uji A/B halaman landing',
    'group' => 'lead',
    'order' => 40,
    'leader' => true,
    'menu' => 'Halaman landing > ubah halaman A > tab Uji A/B',
    'intro' => '<p>Membandingkan dua versi halaman (misalnya formulir panjang dan formulir pendek). Pengunjung dibagi 50:50 di satu alamat.</p>',
    'steps' => [
        [
            'text' => 'Buka halaman A lalu klik <b>Duplikat</b> untuk membuat halaman B. Ubah satu hal saja di halaman B, misalnya formulirnya. Halaman B boleh tetap berstatus Draf.',
            'alt' => 'Tombol Duplikat di halaman ubah halaman landing',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['waitFor' => '.fi-header button:has-text("Duplikat")']],
                'highlight' => ['.fi-header button:has-text("Duplikat")']],
        ],
        [
            'text' => 'Kembali ke halaman A, buka tab <b>Uji A/B</b>. Nyalakan <b>Aktifkan uji A/B</b>, pilih <b>Halaman B</b>, lalu simpan. Halaman A harus berstatus Terbit.',
            'alt' => 'Tab Uji A/B dengan sakelar Aktifkan uji A/B dan pilihan Halaman B',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Uji A/B")']],
                'highlight' => ['[role=tab]:has-text("Uji A/B")', '.fi-fo-field:has-text("Aktifkan uji A/B")', '.fi-fo-field:has-text("Halaman B")']],
        ],
        [
            'text' => 'Pasang satu tautan di iklan: alamat halaman A ditambah <b>?v=auto</b>, misalnya /p/{akun}/{halaman}?v=auto. Alamatnya tertulis di bawah tabel. Untuk memeriksa satu versi, pakai <b>?v=a</b> atau <b>?v=b</b>.',
        ],
        [
            'text' => 'Tabel di tab yang sama membandingkan pengunjung, lead, persentase, closing, dan paket gagal antar per versi. Isi <b>Belanja iklan A</b> dan <b>B</b> dari Meta Ads untuk melihat biaya per closing.',
            'alt' => 'Tabel hasil uji A/B dan isian belanja iklan A dan B',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Uji A/B")'], ['scroll' => '.fi-fo-field:has-text("Belanja iklan B")']],
                'highlight' => ['.fi-fo-field:has-text("Belanja iklan A")', '.fi-fo-field:has-text("Belanja iklan B")', 'table']],
        ],
    ],
    'tips' => ['Pembagian pengunjung tetap untuk orang yang sama (dari alamat IP dan browsernya), jadi pengunjung yang kembali melihat versi yang sama.'],
    'perhatian' => ['Ubah satu hal saja per uji. Bila dua hal berubah sekaligus, Anda tidak tahu mana yang berpengaruh. Biarkan berjalan sampai kedua versi punya cukup lead.'],
];
