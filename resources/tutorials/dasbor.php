<?php

use App\Filament\Pages\Dashboard;

return [
    'title' => 'Dasbor dan pemilih periode',
    'group' => 'mulai',
    'order' => 20,
    'menu' => 'Dasbor',
    'intro' => '<p>Dasbor merangkum angka akun pada satu periode. Agen hanya melihat angka lead miliknya.</p>',
    'pages' => [Dashboard::class],
    'steps' => [
        [
            'text' => 'Pilih periode di dropdown atas halaman: Hari ini, Kemarin, 7 hari terakhir (bawaan), 30 hari terakhir, Bulan ini, Bulan lalu, atau Kustom. Di sebelahnya tertulis tanggal periode dan periode pembandingnya.',
            'alt' => 'Dasbor dengan dropdown periode 7 hari terakhir dan keterangan tanggal di sebelahnya',
            'shot' => ['page' => '', 'highlight' => ['.jl-period-row']],
        ],
        [
            'text' => 'Untuk <b>Kustom</b>, isi tanggal <b>Dari</b> dan <b>Sampai</b> (paling panjang 366 hari). Pilihan terakhir diingat per halaman. Pemilih yang sama ada di Lead, Pipeline, dan Laporan.',
            'alt' => 'Pemilih periode Kustom dengan kolom tanggal Dari dan Sampai',
            'shot' => ['page' => '', 'steps' => [['select' => ['.jl-period-row select', 'custom']], ['waitFor' => '.fi-fo-field:has-text("Sampai")']],
                'highlight' => ['.fi-fo-field:has-text("Dari")', '.fi-fo-field:has-text("Sampai")']],
        ],
        [
            'text' => 'Baris kartu menampilkan lead baru, lead dari iklan, lead Hot, dan rata-rata waktu balas pertama, lalu angka penjualan: order masuk, dibayar atau dikirim, selesai, batal, nilai penjualan, dan konversi. Angka kecil di bawahnya adalah periode sebelumnya.',
            'alt' => 'Kartu ringkas dasbor: lead baru, lead dari iklan, lead Hot, waktu balas pertama, order masuk, dibayar, selesai, batal',
            'shot' => ['page' => '', 'highlight' => ['.fi-wi-stats-overview >> nth=0']],
        ],
        [
            'text' => 'Di bawahnya ada <b>Corong tahap</b>, <b>Sumber lead</b>, <b>Per {item}</b>, dan <b>Per iklan</b>. Klik baris {item} untuk membuka daftar Lead dengan filter yang sama.',
            'alt' => 'Tabel Per produk di dasbor',
            'shot' => ['page' => '', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:has-text("Per produk"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("Per produk"))']],
        ],
        [
            'text' => 'Blok <b>AI & otomatis</b> menghitung balasan AI terkirim, chat yang diserahkan ke agen, voice note belum dibalas, follow-up, sambutan, dan event ke Meta.',
            'alt' => 'Blok AI dan otomatis di dasbor',
            'shot' => ['page' => '', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:has-text("AI & otomatis"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("AI & otomatis"))']],
        ],
        [
            'text' => '<b>Status saat ini</b> tidak mengikuti periode: koneksi tiap nomor WhatsApp, tugas terlambat, dan lead belum ditangani.',
            'alt' => 'Blok Status saat ini berisi koneksi WhatsApp, tugas terlambat, dan lead belum ditangani',
            'shot' => ['page' => '', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:has-text("Status saat ini"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("Status saat ini"))']],
        ],
    ],
    'tips' => ['Hari dihitung menurut zona waktu akun (bawaan WIB). Lead yang masuk pukul 23.30 tetap masuk "Hari ini".'],
    'perhatian' => ['Angka dasbor disimpan sementara selama 60 detik, jadi perubahan terbaru bisa terlambat satu menit.'],
];
