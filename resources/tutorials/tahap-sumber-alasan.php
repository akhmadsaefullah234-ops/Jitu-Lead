<?php

return [
    'title' => 'Tahap pipeline, sumber lead, dan alasan gugur',
    'group' => 'akun',
    'order' => 20,
    'admin' => true,
    'menu' => 'Pengaturan > Tahap pipeline / Sumber lead / Alasan gugur',
    'intro' => '<p>Tiga daftar yang membentuk pipeline dan laporan Anda.</p>',
    'steps' => [
        [
            'text' => '<b>Tahap pipeline</b>: klik ikon panah atas-bawah di atas tabel, lalu seret baris untuk mengubah urutan. Klik <b>Ubah</b> untuk mengganti nama, <b>Jenis</b> (Terbuka, Menang, atau Gugur), <b>Syarat masuk tahap</b> (mis. Alasan gugur), serta <b>Tindak lanjut bawaan</b> dan batas waktunya dalam jam, yang otomatis mengisi tugas lead yang masuk tahap itu.',
            'alt' => 'Daftar Tahap Pipeline dengan jenis, syarat, dan tindak lanjut bawaan',
            'shot' => ['page' => 'stages', 'highlight' => ['.fi-ta-ctn']],
        ],
        [
            'text' => '<b>Sumber lead</b>: daftar asal lead (iklan, formulir web, WhatsApp, media sosial, dan lainnya) untuk laporan Per sumber lead. Klik <b>Buat sumber lead</b> untuk menambah.',
            'alt' => 'Daftar Sumber Lead dengan tombol Buat sumber lead',
            'shot' => ['page' => 'lead-sources', 'highlight' => ['.fi-header button:has-text("Buat sumber lead"), .fi-header a:has-text("Buat sumber lead")']],
        ],
        [
            'text' => '<b>Alasan gugur</b>: pilihan alasan saat lead dipindah ke tahap Gugur (di toko: Batal). Pakai alasan yang spesifik, misalnya Ongkir mahal atau Tidak ada respons, agar laporan berguna.',
            'alt' => 'Daftar Alasan Gugur',
            'shot' => ['page' => 'lost-reasons', 'highlight' => ['.fi-ta-ctn']],
        ],
    ],
    'tips' => ['Mengganti nama tahap toko aman: otomatisasi tahap mengenali tahap dari kedudukannya, bukan dari namanya.'],
    'perhatian' => ['Tahap hanya bisa dihapus bila tidak pernah dipakai lead dan masih ada tahap lain dengan jenis yang sama.'],
];
