<?php

return [
    'title' => 'Profil saya dan profil agensi',
    'group' => 'akun',
    'order' => 5,
    'menu' => 'Menu akun (foto di kanan atas) > Profil saya, dan Pengaturan > Profil agensi',
    'intro' => '<p>Setiap pengguna mengatur nama, email, nomor WhatsApp, foto, dan kata sandinya sendiri di <b>Profil saya</b>. Admin agensi mengatur identitas agensi di <b>Profil agensi</b>: nama, logo, warna merek, WhatsApp bisnis, email kontak, alamat, zona waktu, teks "tentang kami", dan situs.</p>',
    'steps' => [
        ['text' => 'Klik foto atau inisial Anda di kanan atas, lalu <b>Profil saya</b>. Ubah nama, nomor WhatsApp, atau foto (JPG, PNG, WebP, maksimal 2 MB), lalu klik <b>Simpan</b>.'],
        ['text' => 'Untuk mengganti email atau kata sandi, isi <b>Kata sandi lama</b>. Email baru baru dipakai setelah Anda membuka tautan konfirmasi yang kami kirim ke email itu.'],
        ['text' => 'HP atau laptop hilang? Klik <b>Keluar dari perangkat lain</b> dan masukkan kata sandi: semua sesi lain langsung keluar.'],
        ['text' => 'Admin: buka <b>Pengaturan > Profil agensi</b>. Logo, warna, dan WhatsApp bisnis dipakai landing page yang belum mengisi miliknya sendiri; "tentang kami", alamat, email, dan situs tampil di kaki landing page. Variabel <b>{wa_agensi}</b> di pesan sambutan dan template berisi WhatsApp bisnis ini.',
            'alt' => 'Halaman Profil agensi dengan bagian Identitas dan Kontak',
            'shot' => ['page' => 'profil-agensi', 'highlight' => ['.fi-section >> nth=0', '.fi-section >> nth=1']]],
    ],
    'tips' => ['Alamat agensi di tautan (slug) tidak bisa diubah dari sini, supaya formulir dan landing page yang sudah tersebar tetap jalan.'],
    'perhatian' => ['Peran (admin, team leader, agen) hanya bisa diubah admin di menu Tim, tidak dari Profil saya.'],
];
