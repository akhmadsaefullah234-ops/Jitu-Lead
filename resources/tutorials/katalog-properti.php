<?php

use App\Filament\Resources\Properties\PropertyResource;

return [
    'title' => 'Katalog properti',
    'group' => 'properti',
    'order' => 10,
    'industries' => ['properti'],
    'leader' => true,
    'menu' => 'Properti, lalu klik nama properti',
    'intro' => '<p>Daftar proyek dan listing yang Anda pasarkan. Lead, AI, sambutan WhatsApp, follow-up, dan event iklan merujuk ke properti di sini. Admin dan team leader bisa mengubahnya; agen hanya melihat.</p>',
    'pages' => [PropertyResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>Properti</b>. Kolom <b>Jenis</b> membedakan <b>Primary (proyek developer)</b> dan <b>Secondary (listing)</b>. Saring dengan Jenis atau Status (Tersedia, Dibooking, Terjual). Klik <b>Buat properti</b> untuk menambah.',
            'alt' => 'Daftar Properti dengan kolom Jenis, Tipe, Lokasi, Harga mulai, dan tombol Buat properti',
            'shot' => ['page' => 'properties', 'highlight' => ['.fi-header button:has-text("Buat properti"), .fi-header a:has-text("Buat properti")', '.fi-ta-header-cell:has-text("Jenis")']],
        ],
        [
            'text' => 'Klik nama properti. Tab <b>Detail properti</b> berisi Jenis, Nama proyek atau listing, Tipe (Rumah, Apartemen, Ruko, Tanah), Lokasi, Developer (khusus primary), Harga mulai, Status, dan <b>Rincian tambahan</b> (mis. luas tanah, luas bangunan, sertifikat).',
            'alt' => 'Tab Detail properti Cluster Contoh Asri dengan isian lokasi, developer, harga mulai, dan rincian tambahan',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Cluster Contoh Asri"'], ['waitFor' => '[role=tab]:has-text("Detail properti")']],
                'highlight' => ['.fi-fo-field:has-text("Lokasi") >> nth=0', '.fi-fo-field:has-text("Harga mulai (Rp)")', '.fi-fo-field:has-text("Rincian tambahan")']],
        ],
        [
            'text' => 'Tab <b>Pengetahuan AI</b>: catatan atau dokumen yang hanya dipakai AI saat membahas properti ini, misalnya daftar harga per tipe, syarat KPR, atau jam survei.',
            'alt' => 'Tab Pengetahuan AI khusus properti dengan tombol Tulis catatan dan Unggah dokumen',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Cluster Contoh Asri"'], ['click' => '[role=tab]:has-text("Pengetahuan AI")'], ['wait' => 500]],
                'highlight' => ['[role=tab]:has-text("Pengetahuan AI")', 'button:has-text("Tulis catatan")']],
        ],
        [
            'text' => 'Tab <b>Follow-up</b>: rangkaian pesan lanjutan khusus properti ini (lihat bab Follow-up properti). Tab <b>Iklan & promo</b>: harga coret, teks promo yang disebut AI, teks sambutan WhatsApp formulir, dan <b>Pengenal iklan</b> (kata kunci atau ID iklan Meta yang menandai chat iklan sebagai menanyakan properti ini).',
            'alt' => 'Tab Iklan dan promo properti dengan teks promo, teks sambutan, dan pengenal iklan',
            'shot' => ['page' => 'properties', 'steps' => [['click' => 'text="Cluster Contoh Asri"'], ['click' => '[role=tab]:has-text("Iklan & promo")'], ['wait' => 500]],
                'highlight' => ['[role=tab]:has-text("Iklan & promo")', '.fi-fo-field:has-text("Teks promo")', '.fi-fo-field:has-text("Pengenal iklan")']],
        ],
    ],
    'tips' => [
        'Isi Lokasi dan Harga mulai dengan benar. Keduanya dibaca AI, dan Harga mulai dikirim sebagai nilai event konversi ke Meta (bukan nilai transaksi lead).',
        'Ubah Status ke Terjual bila unit sudah habis, agar tidak lagi masuk daftar properti aktif yang ditawarkan ke lead.',
    ],
];
