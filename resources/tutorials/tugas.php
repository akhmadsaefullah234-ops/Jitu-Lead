<?php

use App\Filament\Pages\Tasks;

return [
    'title' => 'Tugas',
    'group' => 'penjualan',
    'order' => 40,
    'menu' => 'Tugas',
    'intro' => '<p>Daftar tindak lanjut semua lead yang masih terbuka, dari isian <b>Aksi berikutnya</b> dan <b>Tenggat aksi</b> di lead. Setiap tahap mengisi tindak lanjut bawaannya sendiri. Agen melihat lead miliknya saja.</p>',
    'pages' => [Tasks::class],
    'steps' => [
        [
            'text' => 'Buka <b>Tugas</b>. Tindak lanjut yang paling mendesak ada di atas; batas yang sudah lewat berwarna merah. Saring dengan <b>Terlambat</b> atau <b>Hari ini</b>.',
            'alt' => 'Daftar Tugas tindak lanjut dengan kolom Batas dan Agen',
            'shot' => ['page' => 'tasks', 'highlight' => ['.fi-ta-row >> nth=0', '.fi-ta-filters-dropdown .fi-dropdown-trigger, .fi-ta-filters-trigger, button[title="Filter"]']],
        ],
        [
            'text' => 'Setelah mengerjakan, klik <b>Selesai</b>. Isi <b>Hasil</b>, lalu <b>Tindak lanjut berikutnya</b> dan <b>Batas waktu</b>-nya, lalu klik <b>Kirim</b>. Hasil tercatat di aktivitas lead. <b>Buka lead</b> membuka halaman lead itu.',
            'alt' => 'Jendela Catat hasil dan jadwalkan berikutnya',
            'shot' => ['page' => 'tasks', 'steps' => [['click' => '.fi-ta-row >> nth=0 >> button:has-text("Selesai")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
    ],
    'tips' => ['Tugas hilang dari daftar saat lead masuk tahap Menang atau Gugur. Jumlah tugas terlambat tampil sebagai angka merah di menu Tugas.'],
];
