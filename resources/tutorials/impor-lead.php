<?php

return [
    'title' => 'Impor lead',
    'group' => 'lead',
    'order' => 50,
    'menu' => 'Impor lead',
    'intro' => '<p>Memasukkan banyak lead sekaligus dari Excel atau Google Sheets, misalnya data pameran atau data lama.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Impor lead</b> dan klik <b>Unduh template</b>. Isi data di bawah judul kolom (nama, telepon, email, catatan) tanpa mengubah judulnya. Kolom wajib: nama, dan telepon atau email.',
            'alt' => 'Halaman Impor lead dengan tombol Unduh template dan Unggah file CSV',
            'shot' => ['page' => 'import-leads', 'highlight' => ['.fi-header button:has-text("Unduh template"), .fi-header a:has-text("Unduh template")', '.fi-header button:has-text("Unggah file CSV")']],
        ],
        [
            'text' => 'Simpan sebagai CSV (maksimal 2.000 baris dan 2 MB), klik <b>Unggah file CSV</b>, pilih berkasnya, isi <b>Sumber lead</b> (mis. Pameran Mei), lalu klik <b>Impor</b>. Admin dan team leader juga bisa memilih <b>Berikan kepada</b> satu agen.',
            'alt' => 'Jendela Unggah file CSV dengan isian File CSV, Sumber lead, dan Berikan kepada',
            'shot' => ['page' => 'import-leads', 'steps' => [['click' => '.fi-header button:has-text("Unggah file CSV")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
    ],
    'tips' => ['Format kolom telepon sebagai Teks agar angka 0 di depan nomor tidak hilang.'],
    'penting' => ['Lead yang nomor atau emailnya sudah ada tidak dibuat dua kali. Lead baru masuk ke tahap pertama dan dibagi rata ke agen, kecuali Anda memilih satu agen. Agen yang mengimpor menjadi pemilik lead itu sendiri.'],
];
