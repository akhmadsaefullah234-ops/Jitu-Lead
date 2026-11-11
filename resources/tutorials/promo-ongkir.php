<?php

return [
    'title' => 'Promo ongkir',
    'group' => 'lead',
    'order' => 45,
    'industries' => ['retail'],
    'leader' => true,
    'menu' => 'Produk > klik produk > tab Iklan & promo > Promo ongkir',
    'intro' => '<p>Potongan ongkir per produk. Angkanya sama dipakai AI saat menjawab ongkir, pada pesanan yang dicatat, dan saat membuat resi.</p>',
    'steps' => [
        [
            'text' => 'Buka produknya, tab <b>Iklan & promo</b>, lalu klik <b>Tambah promo ongkir</b>.',
            'alt' => 'Tombol Tambah promo ongkir di tab Iklan & promo',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Iklan & promo")'], ['wait' => 500]],
                'highlight' => ['button:has-text("Tambah promo ongkir")']],
        ],
        [
            'text' => 'Pilih <b>Jenis promo</b>: Potongan ongkir (Rp), Gratis ongkir, atau Ongkir maksimal (Rp). Pilih <b>Tujuan</b>: Semua tujuan, Pulau Jawa & Bali, luar Jawa & Bali, atau provinsi tertentu. Isi <b>Minimal jumlah</b> bila promo hanya untuk pembelian beberapa pcs, beri nama bila perlu, lalu klik <b>Simpan</b>.',
            'alt' => 'Isian promo ongkir: jenis promo, potongan, tujuan, minimal jumlah, nama promo',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Iklan & promo")'], ['click' => 'button:has-text("Tambah promo ongkir")'], ['wait' => 600]],
                'highlight' => ['.fi-fo-repeater-item >> nth=0']],
        ],
    ],
    'tips' => ['Bila ada beberapa promo yang cocok, hanya satu yang dipakai per pesanan: yang paling hemat untuk pembeli.'],
    'perhatian' => ['Pesanan yang ongkirnya sudah dicatat sebelum promo diisi tidak berubah sendiri. Catat ulang ongkirnya di lead, atau pakai tombol <b>Pakai promo</b> di jendela Buat resi.'],
];
