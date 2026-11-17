<?php

return [
    'title' => 'Tugas',
    'group' => 'penjualan',
    'order' => 40,
    'industries' => ['properti'],
    'menu' => 'Tugas',
    'intro' => '<p>Daftar tindak lanjut semua lead yang masih terbuka. Di agensi properti, tugas mengikuti percakapan: tugas "Hubungi lewat WhatsApp atau telepon" selesai sendiri saat AI atau agen membalas, setiap tahap baru membawa tugasnya (misalnya "Konfirmasi H-1 sebelum survei"), dan chat yang diserahkan AI menjadi tugas "Tanggapi chat yang diserahkan AI" beserta alasannya. Agen melihat lead miliknya saja.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Tugas</b>. Yang paling mendesak ada di atas, dan batas yang sudah lewat berwarna merah. Saring dengan <b>Terlambat</b> atau <b>Hari ini</b>. Tugas yang dibuat otomatis tercatat di aktivitas lead, misalnya "Pindah otomatis" atau "Tugas selesai otomatis".',
            'alt' => 'Daftar Tugas tindak lanjut dengan kolom Batas dan Agen',
            'shot' => ['page' => 'tasks', 'highlight' => ['.fi-ta-row >> nth=0']],
        ],
        [
            'text' => 'Setelah mengerjakan, klik <b>Selesai</b>. Isi <b>Hasil</b>, lalu <b>Tindak lanjut berikutnya</b> dan <b>Batas waktu</b>-nya, lalu klik <b>Kirim</b>. <b>Buka lead</b> membuka halaman lead itu.',
            'alt' => 'Jendela Catat hasil dan jadwalkan berikutnya',
            'shot' => ['page' => 'tasks', 'steps' => [['click' => '.fi-ta-row >> nth=0 >> button:has-text("Selesai")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
    ],
    'tips' => [
        'Tugas hilang dari daftar saat lead masuk tahap Menang atau Gugur. Jumlah tugas terlambat tampil sebagai angka merah di menu Tugas.',
        'Aturan yang membuat dan menutup tugas sendiri ada di Pengaturan > Otomatisasi tahap (aturan 9).',
    ],
];
