<?php

return [
    'title' => 'Formulir pemesanan',
    'group' => 'lead',
    'order' => 10,
    'industries' => ['retail'],
    'leader' => true,
    'menu' => 'Formulir',
    'intro' => '<p>Formulir menangkap nama, WhatsApp, {item}, varian, jumlah, dan alamat pembeli. Pembeli membukanya lewat halaman landing atau tautan langsung /f/{akun}/{formulir}.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Formulir</b>, klik <b>Buat formulir</b>, lalu pilih template <b>Order produk</b> (satu produk: varian, jumlah, alamat kirim, dan Transfer/COD). Isi Nama formulir dan Alamat formulir, lalu klik <b>Buat</b>.',
            'alt' => 'Halaman Buat Formulir dengan daftar template, template Order produk disorot',
            'shot' => ['page' => 'lead-forms/create', 'steps' => [['click' => 'label:has-text("Order produk")']], 'highlight' => ['label:has-text("Order produk")', '.fi-fo-field:has-text("Nama formulir")']],
        ],
        [
            'text' => 'Di bagian <b>Pengaturan</b>, pilih <b>Produk formulir ini</b>. Lead dari formulir itu langsung tercatat menanyakan produk tersebut, sehingga AI dan follow-up produk itu ikut dipakai.',
            'alt' => 'Bagian Pengaturan formulir dengan kolom Produk formulir ini berisi Tas Raket Pro Demo',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Form order singkat"'], ['waitFor' => '.fi-fo-field:has-text("Produk formulir ini")']],
                'highlight' => ['.fi-fo-field:has-text("Produk formulir ini")']],
        ],
        [
            'text' => 'Di <b>Kolom formulir</b>, atur kolom nama, WhatsApp, varian atau warna, jumlah, dan alamat. Seret untuk mengurutkan, dan klik <b>Tambah kolom</b> untuk kolom baru.',
            'alt' => 'Daftar Kolom formulir: nama lengkap, nomor WhatsApp, alamat lengkap, varian, jumlah, pembayaran',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Form order singkat"'], ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Kolom formulir"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Kolom formulir")) .fi-fo-repeater-items, .fi-section:has(.fi-section-header-heading:text-is("Kolom formulir")) ul >> nth=0']],
        ],
        [
            'text' => 'Buka kolom <b>Alamat lengkap</b>. Pada <b>Cara mengisi wilayah</b>, pilih <b>Ketik bebas</b> atau <b>Dropdown wilayah</b> (provinsi, kota, kecamatan, kelurahan dari data wilayah Indonesia yang tersimpan di aplikasi). Di <b>Sub-isian alamat</b>, sembunyikan bagian yang tidak perlu. Di <b>Pilihan alamat</b> ada: sembunyikan kode pos (diisi dari kelurahan), mulai dari kota/kabupaten (provinsi otomatis), pilihan RT/RW atau dusun, dan pemeriksaan RT/RW di kolom jalan.',
            'alt' => 'Pengaturan kolom Alamat lengkap: cara mengisi wilayah, sub-isian, dan pilihan alamat',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Form order singkat"'], ['click' => '.fi-fo-repeater-item-header:has-text("Alamat lengkap")'], ['scroll' => '.fi-fo-field:has-text("Pilihan alamat")']],
                'highlight' => ['.fi-fo-field:has-text("Cara mengisi wilayah")', '.fi-fo-field:has-text("Pilihan alamat")']],
        ],
        [
            'text' => 'Di bagian <b>Pesan sambutan WhatsApp</b>, nyalakan <b>Kirim pesan sambutan WhatsApp otomatis</b>, pilih jeda kirim, dan tulis teksnya atau biarkan kosong untuk teks bawaan. Pembeli disapa di WhatsApp walaupun belum membuka chat.',
            'alt' => 'Bagian Pesan sambutan WhatsApp pada formulir dengan jeda kirim dan teks sambutan',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Form order singkat"'], ['click' => '.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp")) .fi-section-header'], ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp"))']],
        ],
        [
            'text' => 'Klik <b>Simpan perubahan</b>, lalu <b>Buka formulir</b> dan uji di HP dengan mengisinya sendiri memakai nomor Anda. Pratinjau di samping (HP, Tablet, Desktop) sudah memuat perubahan yang belum disimpan.',
            'alt' => 'Formulir order yang dibuka pembeli, tampilan desktop dan HP',
            'shot' => ['page' => '/f/toko-demo/order-tas-raket', 'mobile' => true],
        ],
    ],
    'tips' => ['Formulir punya kolom jebakan tersembunyi dan penanda waktu isi. Kiriman robot ditolak. Kiriman yang meragukan tetap disimpan dengan tanda "Dicurigai spam", supaya pembeli sungguhan tidak hilang.'],
    'perhatian' => ['Pilihan COD atau transfer tidak wajib ada di formulir. Bila kolom Pembayaran dihapus, cara bayar ditanyakan di chat.'],
];
