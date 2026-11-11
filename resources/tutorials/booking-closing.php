<?php

return [
    'title' => 'Booking dan closing',
    'group' => 'penjualan',
    'order' => 23,
    'industries' => ['properti'],
    'menu' => 'Pipeline',
    'intro' => '<p>Tahap <b>Booking</b> dan <b>Closing</b> mencatat unit yang dibeli dan nilai transaksinya. Angka ini dipakai nilai kolom Pipeline dan kartu Akad / closing di Dasbor.</p>',
    'steps' => [
        [
            'text' => 'Di <b>Pipeline</b>, klik <b>Pindah tahap</b> di kartu lead dan pilih <b>Booking</b>. Isi <b>Unit</b> (contoh: Blok C-12) dan <b>Nilai transaksi</b>, lalu klik <b>Pindahkan</b>. Nilai transaksi terisi budget maksimum lead sebagai awal; ganti dengan harga yang disepakati.',
            'alt' => 'Jendela Pindahkan lead ke Booking dengan isian Unit dan Nilai transaksi',
            'shot' => ['page' => 'pipeline', 'steps' => [['click' => '.jl-card:has-text("Hesti Contoh") >> button:has-text("Pindah tahap")'], ['waitFor' => '.fi-modal-window:visible'],
                ['select' => ['.fi-modal-window:visible select', 'Booking']], ['waitFor' => '.fi-modal-window:visible .fi-fo-field:has-text("Nilai transaksi")'],
                ['fill' => ['.fi-modal-window:visible .fi-fo-field:has-text("Unit") input', 'Tower A unit 12-05 (contoh)']]],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has-text("Unit")', '.fi-modal-window:visible .fi-fo-field:has-text("Nilai transaksi")']],
        ],
        [
            'text' => 'Aksi berikutnya di Booking: lengkapi berkas dan proses KPR (tenggat 7 hari). Kartu lead menampilkan nilai transaksinya, dan jumlah nilai tiap kolom ada di kepala kolom.',
            'alt' => 'Kolom Booking di Pipeline dengan kartu lead dan nilai transaksinya',
            'shot' => ['page' => 'pipeline', 'steps' => [['scroll' => '.jl-col:has-text("Imam Contoh")']], 'highlight' => ['.jl-card:has-text("Imam Contoh")']],
        ],
        [
            'text' => 'Saat akad atau pelunasan selesai, pindahkan lead ke <b>Closing</b>. Unit dan nilai transaksi dari Booking sudah terisi; ubah bila nilainya berubah. Aksi berikutnya: minta referral.',
            'alt' => 'Kartu lead di kolom Closing dengan nilai transaksi',
            'shot' => ['page' => 'pipeline', 'steps' => [['scroll' => '.jl-col:has-text("Juli Contoh")']], 'highlight' => ['.jl-card:has-text("Juli Contoh")']],
        ],
    ],
    'tips' => [
        'Closing bisa memicu event Meta Purchase (bawaan untuk properti, mati sampai dinyalakan di Pelacakan iklan). Label WhatsApp Business (uji coba): Order baru saat Booking, Lunas saat Closing.',
        'Dasbor menghitung Booking (lead yang masuk Booking di periode itu) dan Akad / closing beserta jumlah nilainya.',
    ],
    'perhatian' => ['Tanpa Unit dan Nilai transaksi, lead tidak bisa dipindah. Pesannya: "Isi unit yang dibooking." atau "Isi nilai transaksi."'],
];
