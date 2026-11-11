<?php

use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\MediaFolders\MediaFolderResource;

return [
    'title' => 'Pustaka media',
    'group' => 'percakapan',
    'order' => 50,
    'menu' => 'Pustaka media > Pustaka media dan Folder media',
    'intro' => '<p>Tempat menyimpan foto, brosur, dan link yang boleh dikirim lewat chat, sambutan, follow-up, atau oleh AI. Semua peran bisa melihat; admin dan team leader yang menambah.</p>',
    'pages' => [MediaAssetResource::class, MediaFolderResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>Folder media</b> untuk mengatur folder, misalnya per {item} atau per jenis berkas. Klik <b>Folder baru</b> untuk menambah, <b>Ubah</b> untuk mengganti nama.',
            'alt' => 'Daftar Folder media dengan tombol Folder baru',
            'shot' => ['page' => 'media-folders', 'highlight' => ['.fi-header button:has-text("Folder baru"), .fi-header a:has-text("Folder baru")', '.fi-ta-ctn']],
        ],
        [
            'text' => 'Buka <b>Pustaka media</b> dan klik <b>Tambah ke pustaka</b>. Pilih folder, beri judul, pilih <b>Jenis</b>: File atau gambar (JPG, PNG, PDF, Word, Excel, PowerPoint, CSV, TXT, maksimal 10 MB) atau Link. Isi <b>Kapan dipakai</b>: AI membaca kalimat ini untuk memutuskan kapan mengirimnya.',
            'alt' => 'Jendela Tambah ke pustaka dengan folder, judul, jenis, file, dan Kapan dipakai',
            'shot' => ['page' => 'media-assets', 'steps' => [['click' => '.fi-header button:has-text("Tambah ke pustaka")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Berkas yang tersimpan tampil per folder. Matikan <b>Boleh dikirim</b> untuk menyimpan berkas tanpa memakainya. Pilih berkas dari pustaka saat melampirkan di Inbox, pesan sambutan, atau follow-up.',
            'alt' => 'Pustaka media berisi foto produk dan link katalog',
            'shot' => ['page' => 'media-assets', 'highlight' => ['main .fi-ta-ctn, main .fi-page-content > div >> nth=0']],
        ],
    ],
];
