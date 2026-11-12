<?php

return [
    'title' => 'Laporan harian WhatsApp',
    'group' => 'iklan',
    'order' => 40,
    'menu' => 'Pengaturan > Laporan harian',
    'intro' => '<p>Ringkasan lead, penjualan, AI, dan hal yang perlu perhatian, dikirim ke WhatsApp Anda dari nomor resmi JITU LEAD (bukan nomor toko). Angkanya sama dengan Dasbor periode Kemarin dan tidak memuat nama atau nomor pembeli. Aktif sejak awal untuk admin, mati untuk team leader dan agen.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Laporan harian</b>. Nomor penerima diambil dari profil Anda; nomor baru dikonfirmasi dengan kode 6 digit lewat WhatsApp (<b>Kirim kode</b>, lalu <b>Verifikasi</b>).',
            'alt' => 'Nomor WhatsApp penerima dengan tombol Kirim kode',
            'shot' => ['page' => 'laporan-harian', 'highlight' => ['button:has-text("Kirim kode")']],
        ],
        [
            'text' => 'Di <b>Laporan untuk saya</b>, nyalakan <b>Kirim laporan harian ke WhatsApp saya</b>, pilih jam dan hari kirim, dan bila perlu ringkasan mingguan (Senin pagi) atau bulanan (tanggal 1). Admin juga mengatur <b>Bawaan agensi</b> untuk anggota lain. Klik <b>Simpan</b>, lalu <b>Kirim contoh sekarang</b> untuk mencoba.',
            'alt' => 'Bagian Laporan untuk saya dan Bawaan agensi',
            'shot' => ['page' => 'laporan-harian', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Laporan untuk saya"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Laporan untuk saya"))', '.fi-header button:has-text("Kirim contoh sekarang")']],
        ],
    ],
    'tips' => ['Laporan toko yang memakai pengiriman otomatis juga memuat blok Pengiriman: resi otomatis kemarin, COD, gagal, belum dicetak, dan perlu tindakan. Bila WhatsApp tidak bisa dipakai, laporan dikirim ke email.'],
];
