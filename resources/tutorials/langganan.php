<?php

return [
    'title' => 'Langganan',
    'group' => 'akun',
    'order' => 40,
    'admin' => true,
    'menu' => 'Pengaturan > Langganan',
    'intro' => '<p>Paket aktif, masa berlaku, pemakaian terhadap batas paket, dan pilihan paket.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Langganan</b>. <b>Paket Anda</b> menampilkan status dan masa berlaku; <b>Pemakaian</b> menampilkan batas paket, misalnya jumlah halaman landing dan aturan follow-up.',
            'alt' => 'Bagian Paket Anda dan Pemakaian',
            'shot' => ['page' => 'subscription', 'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pemakaian"))']],
        ],
        [
            'text' => 'Di <b>Pilih paket</b>, pilih Bulanan atau Tahunan (hemat 2 bulan), lalu <b>Pilih paket ini</b>. Bayar otomatis lewat QRIS, Virtual Account, atau e-wallet, atau transfer manual bila tersedia.',
            'alt' => 'Pilihan paket bulanan dan tahunan',
            'shot' => ['page' => 'subscription', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Pilih paket"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pilih paket"))']],
        ],
    ],
    'tips' => ['Bila masa percobaan atau langganan berakhir, data tetap tersimpan, bisa dilihat, dan bisa diunduh di Data & privasi.'],
];
