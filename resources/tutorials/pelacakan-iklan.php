<?php

use App\Filament\Pages\TrackingSettings;

return [
    'title' => 'Pelacakan iklan Meta dan TikTok',
    'group' => 'iklan',
    'order' => 10,
    'admin' => true,
    'menu' => 'Pengaturan > Pelacakan iklan',
    'intro' => '<p>JITU LEAD memasang pixel di halaman landing dan formulir yang terbit, dan mengirim event konversi lewat server ke Meta (Conversions API) dan TikTok (Events API), supaya iklan belajar dari lead dan penjualan sungguhan. Google (GA4 / Google Ads) didukung lewat browser.</p>',
    'pages' => [TrackingSettings::class],
    'steps' => [
        [
            'text' => 'Buka <b>Pelacakan iklan</b>. Tiap platform menampilkan status browser dan server serta hasil kiriman terakhir. Klik <b>Atur pelacakan</b>.',
            'alt' => 'Halaman Pelacakan iklan dengan tombol Atur pelacakan, Event dari pipeline, dan Kirim event uji',
            'shot' => ['page' => 'tracking-settings', 'highlight' => ['.fi-header button:has-text("Atur pelacakan")', '.fi-header button:has-text("Event dari pipeline")', '.fi-header button:has-text("Kirim event uji")']],
        ],
        [
            'text' => 'Di bagian <b>Meta</b>, isi <b>Pixel ID</b> dari Events Manager dan <b>Token Conversions API</b> (Events Manager > Settings > Conversions API). Pilih event saat formulir dikirim (bawaan Lead). Untuk menguji, isi <b>Kode uji event</b> dari tab Test Events, lalu kosongkan setelah selesai. TikTok dan Google diisi dengan cara yang sama.',
            'alt' => 'Jendela Atur pelacakan bagian Meta dengan Pixel ID, token, dan kode uji event',
            'shot' => ['page' => 'tracking-settings', 'steps' => [['click' => '.fi-header button:has-text("Atur pelacakan")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-section:has(.fi-section-header-heading:text-is("Meta"))']],
        ],
        [
            'text' => 'Klik <b>Event dari pipeline</b>, nyalakan <b>Kirim event dari pipeline</b>, dan pilih satu event untuk tiap tahap. Bawaan toko: Hot menjadi Lead, Order masuk menjadi InitiateCheckout, Dikemas & dikirim menjadi Purchase. Event dikirim saat lead masuk tahap itu, siapa pun yang memindahkannya.',
            'alt' => 'Jendela Event dari pipeline dengan pilihan event per tahap',
            'shot' => ['page' => 'tracking-settings', 'steps' => [['click' => '.fi-header button:has-text("Event dari pipeline")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
    ],
    'tips' => [
        'Setiap lead mengirim satu event jenis yang sama sekali saja, jadi tidak terhitung ganda. Purchase dikirim lagi untuk setiap pesanan baru.',
        'Data kontak dikirim dalam bentuk hash (SHA-256), tidak pernah dalam bentuk asli. Setiap event yang terkirim tercatat di aktivitas lead.',
    ],
    'perhatian' => ['Token tersimpan terenkripsi dan tidak ditampilkan lagi. Jangan menempelkan token di chat atau dokumen.'],
];
