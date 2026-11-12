<?php

return [
    'title' => 'Lead gugur dan alasannya',
    'group' => 'penjualan',
    'order' => 24,
    'industries' => ['properti'],
    'menu' => 'Pipeline, dan Pengaturan > Alasan gugur',
    'intro' => '<p>Lead yang tidak jadi membeli dipindah ke tahap <b>Gugur</b> dengan satu alasan. Alasan yang konsisten membuat laporan menunjukkan di mana penjualan paling sering lepas.</p>',
    'steps' => [
        [
            'text' => 'Di <b>Pipeline</b>, klik <b>Pindah tahap</b>, pilih <b>Gugur</b>, lalu pilih <b>Alasan gugur</b> dan klik <b>Pindahkan</b>. Alasan bawaan: Budget tidak cukup, KPR ditolak, Membeli di tempat lain, Tidak ada respons, Hanya cek harga, dan Lokasi tidak cocok.',
            'alt' => 'Jendela Pindahkan lead ke Gugur dengan pilihan Alasan gugur',
            'shot' => ['page' => 'pipeline', 'steps' => [['click' => '.jl-card:has-text("Lukman Contoh") >> button:has-text("Pindah tahap")'], ['waitFor' => '.fi-modal-window:visible'],
                ['select' => ['.fi-modal-window:visible select', 'Gugur']], ['waitFor' => '.fi-modal-window:visible .fi-fo-field:has-text("Alasan gugur")'],
                ['select' => ['.fi-modal-window:visible .fi-fo-field:has-text("Alasan gugur") select', 'Lokasi tidak cocok']]],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has-text("Alasan gugur")']],
        ],
        [
            'text' => 'Admin mengubah daftar alasan di <b>Pengaturan > Alasan gugur</b>: klik <b>Buat alasan gugur</b> untuk menambah, atau ubah dan hapus alasan yang tidak dipakai.',
            'alt' => 'Daftar Alasan gugur di Pengaturan',
            'shot' => ['page' => 'lost-reasons', 'highlight' => ['.fi-ta-table']],
        ],
    ],
    'tips' => ['Lead yang gugur karena KPR ditolak sering bisa ditawari unit lain atau cara bayar lain beberapa bulan kemudian. Simpan catatannya di lead.'],
];
