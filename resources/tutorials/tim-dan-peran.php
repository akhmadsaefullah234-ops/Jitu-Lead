<?php

use App\Filament\Resources\Team\TeamResource;

// Bab "Masuk dan peran pengguna". Format: docs/tutorial.md.
return [
    'title' => 'Masuk dan peran pengguna',
    'group' => 'mulai',
    'order' => 10,
    'admin' => true,
    'menu' => 'Pengaturan > Tim',
    'intro' => '<p>Setiap akun punya data sendiri yang terpisah dari akun lain. Ada tiga peran:</p><ul>'
        .'<li><b>Admin</b>: semua data dan semua pengaturan, termasuk Koneksi WhatsApp, Mode AI, Pengiriman, dan Langganan.</li>'
        .'<li><b>Team leader</b>: semua lead, chat, dan kiriman, ditambah Formulir dan Halaman landing.</li>'
        .'<li><b>Agen</b>: hanya lead miliknya, chat lead itu, dan kiriman lead itu.</li></ul>',
    'pages' => [TeamResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>Pengaturan > Tim</b>. Tabel menampilkan nama, email, peran, porsi lead, dan status tiap anggota.',
            'alt' => 'Halaman Tim berisi empat anggota demo dengan peran Admin, Team leader, dan Agen',
            'shot' => ['page' => 'team/teams', 'highlight' => ['.fi-sidebar-item-btn:has-text("Tim")', '.fi-ta-ctn']],
        ],
        [
            'text' => 'Klik <b>Tambah anggota</b>, isi Nama, Email, Kata sandi sementara (minimal 10 karakter), dan Peran, lalu simpan. Berikan kata sandinya ke anggota baru dan minta ia menggantinya setelah masuk.',
            'alt' => 'Jendela Tambah anggota tim dengan kolom Nama, Email, Kata sandi sementara, dan Peran',
            'shot' => ['page' => 'team/teams', 'steps' => [['click' => 'button:has-text("Tambah anggota")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Untuk mengatur siapa yang mendapat lead baru, klik <b>Ubah</b> pada baris anggota. Agen otomatis ikut dibagi. Nyalakan <b>Ikut dibagi lead baru</b> untuk Admin atau Team leader yang juga menangani lead, lalu isi <b>Porsi lead (%)</b> bila pembagiannya tidak rata. Kosongkan semua porsi untuk dibagi rata.',
            'alt' => 'Jendela Ubah anggota dengan pilihan Peran, Status, Ikut dibagi lead baru, dan Porsi lead',
            'shot' => ['page' => 'team/teams', 'steps' => [['click' => 'tr:has-text("Agen Demo Satu") button:has-text("Ubah")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has-text("Ikut dibagi lead baru")', '.fi-modal-window:visible .fi-fo-field:has-text("Porsi lead")']],
        ],
        [
            'text' => 'Ubah <b>Status</b> menjadi Nonaktif untuk anggota yang libur atau keluar. Anggota nonaktif tidak bisa masuk dan tidak mendapat lead baru.',
        ],
        [
            'text' => 'Klik <b>Pemindahan lead</b> untuk lead yang lama tidak ditanggapi. Pilih <b>Manual</b> (admin memindahkan sendiri dari daftar Lead) atau <b>Otomatis</b> (lead pindah ke agen lain setelah batas waktu), lalu isi batas waktunya dalam menit.',
            'alt' => 'Jendela Pemindahan lead yang belum ditanggapi dengan pilihan cara pemindahan dan batas waktu',
            'shot' => ['page' => 'team/teams', 'steps' => [['click' => 'button:has-text("Pemindahan lead")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
    ],
    'tips' => ['Batas waktu yang sama dipakai untuk penanda "Belum ditanggapi" di daftar Lead, walaupun pemindahan diatur Manual.'],
    'perhatian' => ['Akun harus punya minimal satu admin aktif. Peran dan status admin terakhir tidak bisa diubah.'],
];
