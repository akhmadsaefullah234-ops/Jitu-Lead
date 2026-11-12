<?php

return [
    'title' => 'Pesan sambutan WhatsApp',
    'group' => 'lead',
    'order' => 15,
    'leader' => true,
    'menu' => 'Formulir > buka formulir > Pesan sambutan WhatsApp',
    'intro' => '<p>Begitu pembeli mengisi formulir, JITU LEAD menyapanya di WhatsApp walaupun pembeli tidak lanjut ke chat. Balasan pembeli masuk ke percakapan lead itu, lalu AI atau agen melanjutkan. Untuk toko, sambutan aktif sejak awal.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Formulir</b>, klik formulirnya, lalu buka bagian <b>Pesan sambutan WhatsApp</b>. Nyalakan <b>Kirim pesan sambutan WhatsApp otomatis</b>.',
            'alt' => 'Bagian Pesan sambutan WhatsApp di halaman formulir dengan sakelar kirim otomatis',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Form order singkat"'], ['click' => '.fi-section-header:has-text("Pesan sambutan WhatsApp")'], ['wait' => 400]],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp"))']],
        ],
        [
            'text' => 'Pilih <b>Jeda kirim</b>: 1, 2, 5, atau 10 menit (bawaan 2). Bila dalam jeda itu pembeli sudah chat duluan, sambutan tidak dikirim. Lampiran dari pustaka media boleh ditambahkan.',
            'alt' => 'Pilihan Jeda kirim dan lampiran pesan sambutan',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Form order singkat"'], ['click' => '.fi-section-header:has-text("Pesan sambutan WhatsApp")'], ['wait' => 400]],
                'highlight' => ['.fi-fo-field:has-text("Jeda kirim")']],
        ],
        [
            'text' => 'Tulis teksnya, atau biarkan kosong untuk memakai teks sambutan {item} (tab <b>Iklan & promo</b> di halaman {item}) atau teks bawaan toko. Klik variabel di bawah kotak untuk menyisipkannya, lalu periksa pratinjau dan klik <b>Simpan</b>.',
            'alt' => 'Kotak teks sambutan dengan tombol variabel dan pratinjau pesan',
            'shot' => ['page' => 'lead-forms', 'steps' => [['click' => 'text="Form order singkat"'], ['click' => '.fi-section-header:has-text("Pesan sambutan WhatsApp")'], ['wait' => 400],
                ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp")) textarea']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pesan sambutan WhatsApp")) textarea']],
        ],
        [
            'text' => 'Halaman landing yang memuat formulir punya tab <b>Pesan sambutan</b> sendiri: <b>Ikuti pengaturan formulir</b> (bawaan), <b>Pakai teks khusus halaman ini</b>, atau <b>Matikan di halaman ini</b>.',
            'alt' => 'Tab Pesan sambutan di halaman landing dengan tiga pilihan',
            'shot' => ['page' => 'landing-pages', 'steps' => [['click' => 'text="Tas Raket Pro Demo"'], ['click' => '[role=tab]:has-text("Pesan sambutan")'], ['wait' => 400]],
                'highlight' => ['[role=tab]:has-text("Pesan sambutan")', '.fi-fo-field:has-text("Pesan sambutan WhatsApp untuk formulir")']],
        ],
    ],
    'tips' => [
        'Variabel: {nama} (nama depan), {produk}, {ringkasan} (produk, varian, jumlah, tujuan), {total} (harga x jumlah + ongkir), {ongkir}, {kurir}, {whatsapp}, {alamat}, {varian}, {warna}, {jumlah}, {agensi}, {agen}, dan {jawaban:kunci} untuk jawaban isian lain.',
        'Di Inbox pesan ini bertanda "Sambutan otomatis". Hasilnya (terkirim, dilewati, gagal) tercatat di aktivitas lead.',
    ],
    'perhatian' => [
        'Sambutan dikirim sekali per pembeli per formulir. Pembeli lama yang mengisi lagi hanya disapa bila tidak ada pesan keluar ke nomornya dalam 24 jam terakhir.',
        'Pukul 21.00 sampai 05.00 sambutan menunggu sampai 05.00. Nomor yang bukan nomor WhatsApp valid tidak dikirimi.',
    ],
];
