<?php

return [
    'title' => 'Pesan status kiriman ke pembeli',
    'group' => 'pengiriman',
    'order' => 60,
    'industries' => ['retail'],
    'admin' => true,
    'menu' => 'Pengaturan > Pengiriman > Pesan status kiriman',
    'intro' => '<p>Pembeli menerima WhatsApp otomatis saat status paketnya berubah, sekali per status per resi, lewat nomor WhatsApp pemilik lead.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Pengaturan > Pengiriman</b>, gulir ke <b>Pesan status kiriman</b>. Status yang bisa diatur: Diambil kurir (aktif), Dalam perjalanan (nonaktif), Sedang diantar (aktif), Gagal diantar (aktif), dan Diterima (aktif). Buka satu status untuk menyalakan <b>Kirim ke pembeli</b> dan mengubah teksnya.',
            'alt' => 'Bagian Pesan status kiriman dengan daftar status',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Pesan status kiriman"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pesan status kiriman")) .fi-section-header >> nth=0']],
        ],
        [
            'text' => '<b>Sedang diantar</b> punya dua teks: untuk paket biasa dan untuk COD, yang menyebut uang yang perlu disiapkan ({cod_total}). Variabel: {nama}, {produk}, {resi}, {kurir}, {link_lacak}, {alasan}, {cod_total}, {agensi}. Klik <b>Simpan pesan status</b>.',
            'alt' => 'Teks Sedang diantar untuk bukan COD dan untuk COD',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Sedang diantar"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Sedang diantar"))']],
        ],
    ],
    'tips' => [
        'Pesan resi terbit memakai pesan resi dari Otomatisasi tahap (aturan 4), bukan dari bagian ini.',
        'Gagal diantar juga memberi tahu agen (lonceng dan email) dan menandai chat "Perlu tindakan". Diterima memindahkan lead ke Selesai bila aturan Otomatisasi tahap itu aktif.',
    ],
    'perhatian' => [
        'Pukul 21.00 sampai 05.00 pesan menunggu sampai 05.00. Pesan untuk status yang sudah lewat lama tidak dikirim lagi.',
        'Paket dikembalikan (retur) tidak pernah dikabarkan ke pembeli; hanya agen yang diberi tahu. Pelacakan cadangan berjalan tiap 2 jam bila callback Everpro terlewat.',
    ],
];
