<?php

return [
    'title' => 'Menu Pengiriman dan cetak label',
    'group' => 'pengiriman',
    'order' => 50,
    'industries' => ['retail'],
    'menu' => 'Pengiriman',
    'intro' => '<p>Semua kiriman yang dibuat lewat penyedia pengiriman. Menu ini tampil bila akun sudah terhubung ke Everpro.</p>',
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
            'alt' => 'Tombol Cetak, Tandai sudah dicetak, dan Batalkan di baris kiriman',
            'shot' => ['page' => 'kiriman', 'highlight' => ['.fi-ta-row >> nth=0 >> .fi-ta-actions']],
        ],
        [
            'text' => 'Klik <b>Batalkan</b> di baris kiriman untuk membatalkan pesanan itu di penyedia (hanya sebelum paket diambil kurir). Jendela konfirmasi menyebut resi atau nomor pesanan dan nama pembeli; isi <b>Alasan</b> bila perlu (bawaan: Dibatalkan toko). Kiriman langsung pindah ke tab Dibatalkan. Kiriman yang sudah diambil kurir tidak bisa dibatalkan dari sini: arahkan kursor ke statusnya, lalu hubungi penyedia.',
            'alt' => 'Jendela Batalkan kiriman dengan nomor resi, nama pembeli, dan kolom Alasan',
            'shot' => ['page' => 'kiriman', 'steps' => [['click' => '.fi-ta-row >> nth=0 >> button:has-text("Batalkan")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Admin dan team leader bisa <b>Hapus</b> kiriman dari daftar bila kiriman itu tidak lagi aktif di penyedia: sudah dibatalkan, gagal sebelum diterima penyedia, atau resi yang ditautkan manual. Hapus tidak membatalkan apa pun di penyedia; kiriman yang masih aktif harus dibatalkan dulu. Setelah dihapus, lead bisa dibuatkan resi baru. Untuk banyak kiriman sekaligus, centang barisnya lalu pilih <b>Tindakan > Batalkan terpilih</b> atau <b>Hapus terpilih</b> (paling banyak 50 sekali jalan; yang tidak memenuhi syarat dilewati dan disebutkan).',
            'alt' => 'Tab Dibatalkan dengan tombol Hapus di baris kiriman',
            'shot' => ['page' => 'kiriman', 'steps' => [['click' => '.fi-tabs-item:has-text("Dibatalkan")'], ['wait' => 800], ['scroll' => '.fi-ta-row >> nth=0 >> button:has-text("Hapus")']],
                'highlight' => ['.fi-ta-row >> nth=0 >> button:has-text("Hapus")', '.fi-tabs-item:has-text("Dibatalkan")']],
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
