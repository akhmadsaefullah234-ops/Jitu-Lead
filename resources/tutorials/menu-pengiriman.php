<?php

use App\Filament\Resources\Shipments\ShipmentResource;

return [
    'title' => 'Menu Pengiriman dan cetak label',
    'group' => 'pengiriman',
    'order' => 50,
    'industries' => ['retail'],
    'menu' => 'Pengiriman',
    'intro' => '<p>Semua kiriman yang dibuat lewat penyedia pengiriman. Menu ini tampil bila akun sudah terhubung ke Everpro.</p>',
    'pages' => [ShipmentResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>Pengiriman</b>. Tab yang tersedia: Menunggu resi, Menunggu saldo, <b>Belum dicetak</b> (terbuka pertama, resi terlama di atas), Sudah dicetak menunggu dijemput, Dalam perjalanan, Perlu tindakan, Selesai, dan Dibatalkan.',
            'alt' => 'Halaman Kiriman dengan tab Menunggu resi, Belum dicetak, Dalam perjalanan, Perlu tindakan',
            'shot' => ['page' => 'kiriman', 'highlight' => ['.fi-tabs']],
        ],
        [
            'text' => 'Klik <b>Cetak semua yang belum dicetak (N)</b> untuk satu PDF berisi semua label, urut dari resi terlama. Nyalakan pilihan daftar pengemasan bila perlu halaman pertama berisi daftar barang. Setelah PDF jadi, kiriman pindah ke tab Sudah dicetak.',
            'alt' => 'Jendela Cetak semua label yang belum dicetak',
            'shot' => ['page' => 'kiriman', 'steps' => [['click' => 'button:has-text("Cetak semua yang belum dicetak")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Untuk sebagian saja, centang barisnya lalu pilih <b>Tindakan > Cetak label terpilih</b>, atau klik <b>Cetak</b> di satu baris. <b>Tandai sudah dicetak</b> memindahkan baris tanpa mencetak.',
            'alt' => 'Tombol Cetak dan Tandai sudah dicetak di baris kiriman',
            'shot' => ['page' => 'kiriman', 'highlight' => ['.fi-ta-row >> nth=0 >> .fi-ta-actions']],
        ],
        [
            'text' => '<b>Riwayat cetak</b> menyimpan setiap cetakan beserta tautan unduhnya.',
            'alt' => 'Jendela Riwayat cetak label',
            'shot' => ['page' => 'kiriman', 'steps' => [['click' => 'button:has-text("Riwayat cetak")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Tab <b>Perlu tindakan</b> berisi paket gagal antar, retur, label gagal, dan resi gagal. Bila label gagal diambil, klik <b>Ambil ulang label</b> di baris itu.',
            'alt' => 'Tab Perlu tindakan berisi kiriman gagal antar',
            'shot' => ['page' => 'kiriman', 'steps' => [['click' => '.fi-tabs-item:has-text("Perlu tindakan")'], ['wait' => 500]],
                'highlight' => ['.fi-tabs-item:has-text("Perlu tindakan")', '.fi-ta-row >> nth=0']],
        ],
    ],
    'tips' => ['Cari berdasarkan nama, HP, atau nomor resi. Filter tersedia untuk tanggal resi, kurir, cara bayar (COD atau transfer), dan produk.'],
    'perhatian' => ['Tautan PDF berlaku 1 jam. Label tiap paket disimpan 90 hari, dan PDF cetakan di Riwayat cetak 30 hari.'],
];
