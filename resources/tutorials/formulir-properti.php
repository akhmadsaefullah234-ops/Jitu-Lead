<?php

return [
    'title' => 'Formulir properti',
    'group' => 'lead',
    'order' => 10,
    'industries' => ['properti'],
    'leader' => true,
    'menu' => 'Formulir',
    'intro' => '<p>Formulir menangkap nama, WhatsApp, dan minat calon pembeli: tipe unit, perkiraan budget, dan rencana cara bayar. Calon pembeli membukanya lewat halaman landing, iklan, atau tautan langsung /f/{akun}/{formulir}.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Formulir</b>, klik <b>Buat formulir</b>, lalu pilih template <b>Minat properti</b> (tipe yang diminati, perkiraan budget, rencana cara bayar, dan pertanyaan). Ada juga <b>Janji temu / survei</b> bila pengunjung boleh memilih tanggal dan waktu kunjungan sendiri. Isi Nama formulir dan Alamat formulir, lalu klik <b>Buat</b>.',
            'alt' => 'Halaman Buat Formulir dengan daftar template, template Minat properti disorot',
            'shot' => ['page' => 'lead-forms/create', 'steps' => [['click' => 'label:has-text("Minat properti")']], 'highlight' => ['label:has-text("Minat properti")', '.fi-fo-field:has-text("Nama formulir")']],
        ],
        [
            'text' => 'Di bagian <b>Pengaturan</b>, pilih <b>Properti formulir ini</b>. Lead dari formulir itu langsung tercatat menanyakan properti tersebut, sehingga AI, sambutan, dan follow-up properti itu ikut dipakai.',
            'alt' => 'Bagian Pengaturan formulir dengan kolom Properti formulir ini berisi Cluster Contoh Asri',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Minat Cluster Contoh Asri"'], ['waitFor' => '.fi-fo-field:has-text("Properti formulir ini")']],
                'highlight' => ['.fi-fo-field:has-text("Properti formulir ini")']],
        ],
        [
            'text' => 'Di <b>Kolom formulir</b>, ganti pilihan <b>Tipe yang diminati</b> dengan tipe unit Anda, dan sesuaikan rentang <b>Perkiraan budget</b>. Seret untuk mengurutkan, dan klik <b>Tambah kolom</b> untuk kolom baru. Jawaban tipe, budget, dan cara bayar tampil di bagian <b>Jawaban formulir</b> pada halaman lead.',
            'alt' => 'Daftar Kolom formulir: nama, nomor WhatsApp, tipe yang diminati, perkiraan budget, rencana cara bayar, pertanyaan',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Minat Cluster Contoh Asri"'], ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Kolom formulir"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Kolom formulir")) .fi-fo-repeater-items, .fi-section:has(.fi-section-header-heading:text-is("Kolom formulir")) ul >> nth=0']],
        ],
        [
            'text' => 'Bagian <b>Pesan sambutan WhatsApp</b> mati sebagai bawaan untuk agensi properti. Nyalakan <b>Kirim pesan sambutan WhatsApp otomatis</b>, pilih jeda kirim, dan tulis teksnya atau biarkan kosong. Teks bawaannya menyapa calon pembeli dan menawarkan jadwal survei lokasi atau kiriman brosur dan daftar harga.',
            'alt' => 'Bagian Pesan sambutan WhatsApp pada formulir properti',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Minat Cluster Contoh Asri"'], ['click' => '.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp")) .fi-section-header'],
                ['click' => '.fi-fo-field:has-text("Kirim pesan sambutan WhatsApp otomatis") button[role=switch]'], ['wait' => 800], ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp"))']],
        ],
        [
            'text' => 'Klik <b>Simpan perubahan</b>, lalu <b>Buka formulir</b> dan uji di HP dengan mengisinya sendiri memakai nomor Anda.',
            'alt' => 'Formulir Minat properti yang dibuka calon pembeli, tampilan desktop dan HP',
            'shot' => ['page' => '/f/agensi-demo/minat-cluster-contoh', 'mobile' => true],
        ],
    ],
    'tips' => ['Pilihan pada formulir bukan janji harga. Tulis rentang budget yang memang tersedia, dan konfirmasi harga serta cara bayar di chat.'],
    'perhatian' => ['Jawaban budget dan cara bayar dari formulir tidak otomatis mengisi kolom Budget dan Cara bayar lead. Salin setelah dikonfirmasi (lihat bab Menggali kebutuhan pembeli).'],
];
