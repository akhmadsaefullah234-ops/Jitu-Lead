<?php

return [
    'title' => 'Otomatisasi tahap produk digital',
    'group' => 'penjualan',
    'order' => 30,
    'industries' => ['digital'],
    'admin' => true,
    'menu' => 'Pengaturan > Otomatisasi tahap',
    'intro' => '<p>Empat aturan yang memindahkan pembelian produk digital antar tahap sendiri. Setiap perpindahan otomatis tercatat di aktivitas lead.</p>',
    'steps' => [
        ['text' => 'Buka <b>Otomatisasi tahap</b>. 1. Pembeli konfirmasi pembelian ke Menunggu pembayaran; 2. Gambar saat Menunggu pembayaran ditandai "Bukti transfer masuk" untuk dicek orang.'],
        ['text' => '3. Belum bayar di Menunggu pembayaran ke Tidak jadi bayar setelah sekian <b>jam</b> (bawaan 48); lead yang sudah mengirim bukti transfer tidak dipindah. 4. Pembeli Tidak jadi bayar yang chat lagi kembali ke Lead baru. Klik <b>Simpan</b>.'],
    ],
    'tips' => ['Agensi digital baru mulai dengan keempat aturan menyala. Agensi yang pindah ke produk digital saat sudah punya lead menyalakannya sendiri di halaman ini.'],
    'perhatian' => ['Lead yang sudah Lunas tidak pernah dipindah otomatis.'],
];
