<?php

return [
    'title' => 'Paket gagal antar',
    'group' => 'pengiriman',
    'order' => 70,
    'industries' => ['retail'],
    'menu' => 'Pengiriman > Perlu tindakan, dan chat di Inbox',
    'intro' => '<p>Saat kurir gagal mengantar, pembeli diberi tahu lewat WhatsApp, pemilik lead (atau admin bila lead belum punya pemilik) mendapat notifikasi lonceng dan email, dan kiriman masuk tab <b>Perlu tindakan</b>.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Pengiriman</b>, tab <b>Perlu tindakan</b>. Kolom status menampilkan alasan dari kurir.',
            'alt' => 'Tab Perlu tindakan berisi paket gagal diantar',
            'shot' => ['page' => 'kiriman', 'steps' => [['click' => '.fi-tabs-item:has-text("Perlu tindakan")'], ['wait' => 500]],
                'highlight' => ['.fi-ta-row >> nth=0']],
        ],
        [
            'text' => 'Buka lead itu, klik <b>Pengiriman</b> lalu <b>Lacak paket</b> untuk melihat riwayat perjalanan. Hubungi pembeli: pastikan alamat dan waktu terima, dan untuk COD pastikan pembeli siap membayar.',
            'alt' => 'Menu Pengiriman lead dengan pilihan Lacak paket',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Kiki Contoh"'], ['click' => '.fi-header button:has-text("Pengiriman")'], ['waitFor' => '.fi-dropdown-panel:visible']],
                'highlight' => ['.fi-dropdown-panel:visible button:has-text("Lacak paket")']],
        ],
    ],
    'tips' => ['Minta kurir mengantar ulang lewat dashboard Everpro atau kantor kurir. Bila pembeli tidak bisa dihubungi atau menolak, paket akan kembali (retur).'],
    'perhatian' => ['Paket yang kembali tidak membatalkan lead otomatis. Putuskan sendiri: kirim ulang atau pindahkan lead ke Batal.'],
];
