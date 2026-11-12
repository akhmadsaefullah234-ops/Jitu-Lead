<?php

return [
    'title' => 'Produk dan pengaturan per produk',
    'group' => 'lead',
    'order' => 40,
    'industries' => ['retail'],
    'leader' => true,
    'menu' => 'Produk, lalu klik produknya',
    'intro' => '<p>Semua yang hanya berlaku untuk satu produk diatur di halaman produk itu. Admin dan team leader bisa mengubahnya; agen hanya melihat.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Produk</b>. Klik <b>Buat produk</b> untuk menambah, atau klik nama produk untuk mengubahnya.',
            'alt' => 'Daftar Produk dengan tombol Buat produk',
            'shot' => ['page' => 'properties', 'highlight' => ['.fi-header button:has-text("Buat produk"), .fi-header a:has-text("Buat produk")', '.fi-ta-row >> nth=0']],
        ],
        [
            'text' => 'Halaman produk punya empat tab. <b>Detail produk</b>: nama, kategori, harga, stok, berat, ukuran, dan rincian tambahan. Isi <b>Berat</b> siap kirim termasuk kemasan agar ongkir dan resi tepat; kosong berarti memakai berat bawaan di Pengaturan > Pengiriman.',
            'alt' => 'Tab Detail produk dengan isian harga, stok, berat, dan ukuran',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['waitFor' => '[role=tab]:has-text("Detail produk")']],
                'highlight' => ['.product-tabs [role=tablist], [role=tablist]', '.fi-fo-field:has-text("Berat (gram)")']],
        ],
        [
            'text' => '<b>Pengetahuan AI</b>: catatan atau dokumen yang hanya dipakai AI saat membahas produk ini (<b>Tulis catatan</b> atau <b>Unggah dokumen</b>).',
            'alt' => 'Tab Pengetahuan AI khusus produk dengan tombol Tulis catatan dan Unggah dokumen',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Pengetahuan AI")'], ['wait' => 500]],
                'highlight' => ['[role=tab]:has-text("Pengetahuan AI")', 'button:has-text("Tulis catatan")']],
        ],
        [
            'text' => '<b>Follow-up</b>: rangkaian pesan lanjutan khusus produk ini (lihat bab Follow-up otomatis). <b>Iklan & promo</b>: harga coret, teks promo yang disebut AI, teks sambutan WhatsApp formulir, <b>Promo ongkir</b>, dan <b>Pengenal iklan</b> (kata kunci atau ID iklan Meta yang menandai chat iklan sebagai menanyakan produk ini).',
            'alt' => 'Tab Iklan & promo dengan harga coret, teks promo, teks sambutan, dan pengenal iklan',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Iklan & promo")'], ['wait' => 500]],
                'highlight' => ['[role=tab]:has-text("Iklan & promo")', '.fi-fo-field:has-text("Harga coret")', '.fi-fo-field:has-text("Pengenal iklan")']],
        ],
    ],
    'tips' => ['Harga coret harus lebih tinggi dari harga di tab Detail produk; harga di tab Detail menjadi harga promo.'],
];
