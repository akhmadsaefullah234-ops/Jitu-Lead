<?php

use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;

return [
    'title' => 'Follow-up otomatis',
    'group' => 'percakapan',
    'order' => 60,
    'admin' => true,
    'menu' => 'Follow-up otomatis, dan tab Follow-up di halaman {item}',
    'intro' => '<p>Pesan lanjutan yang terkirim sendiri pada hari dan jam tertentu setelah lead masuk sebuah tahap. Pesan dikirim sekali per lead di tahap itu; lead yang sudah pindah tahap tidak lagi menerimanya.</p>',
    'pages' => [FollowUpRuleResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>Follow-up otomatis</b>. Kotak di atas menjelaskan apakah <b>follow-up umum</b> sedang dipakai dan alasannya. Klik <b>Ubah pengaturan</b> untuk memilih: <b>Otomatis</b> (dipakai bila toko punya paling banyak 1 {item} aktif), <b>Selalu dipakai</b>, atau <b>Tidak dipakai</b>.',
            'alt' => 'Kotak status follow-up umum dengan tombol Ubah pengaturan',
            'shot' => ['page' => 'follow-up-rules', 'highlight' => ['.fi-callout >> nth=0']],
        ],
        [
            'text' => 'Bila follow-up umum tidak dipakai, lead yang belum punya {item} menerima pesan cadangan <b>Tanya produk</b> sekali: daftar {item} aktif bernomor. Balasan angka, misalnya "2", langsung mengisi {item} lead. Klik <b>Atur pesan</b> untuk mengubah teks, tahap, dan jamnya.',
            'alt' => 'Kotak pesan cadangan Tanya produk dengan tombol Atur pesan',
            'shot' => ['page' => 'follow-up-rules', 'highlight' => ['.fi-callout:has-text("Tanya produk")']],
        ],
        [
            'text' => 'Klik <b>Buat aturan follow-up</b>. Isi nama, tahap, <b>Kirim pada</b> (H+1 = sehari setelah lead masuk tahap), <b>Jam kirim (WIB)</b>, isi pesan dengan variabel, dan lampiran dari pustaka media bila perlu.',
            'alt' => 'Jendela aturan follow-up dengan tahap, Kirim pada, Jam kirim, dan isi pesan',
            'shot' => ['page' => 'follow-up-rules', 'steps' => [['click' => '.fi-header button:has-text("Buat aturan follow-up")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Untuk satu {item}: buka halaman {item}, tab <b>Follow-up</b>, lalu <b>Tambah langkah</b>. <b>Salin rangkaian</b> menyalin dari follow-up umum atau dari {item} lain; salinan selalu nonaktif, jadi periksa lalu aktifkan.',
            'alt' => 'Tab Follow-up di halaman produk dengan tombol Salin rangkaian dan Tambah langkah',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Follow-up")'], ['wait' => 500]],
                'highlight' => ['button:has-text("Salin rangkaian")', 'button:has-text("Tambah langkah")']],
        ],
    ],
    'tips' => [
        'Bila {item} lead punya aturan aktif untuk tahap itu, hanya rangkaian {item} yang dikirim; bila tidak, rangkaian umum. Tidak pernah keduanya.',
        'Follow-up yang jatuh dalam 2 jam setelah pesan sambutan ditunda sampai 2 jam lewat, agar pembeli tidak menerima dua pesan beruntun.',
    ],
];
