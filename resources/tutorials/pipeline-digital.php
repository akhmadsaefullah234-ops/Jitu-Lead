<?php

return [
    'title' => 'Pipeline produk digital',
    'group' => 'penjualan',
    'order' => 20,
    'industries' => ['digital'],
    'menu' => 'Pipeline',
    'intro' => '<p>Papan tahap penjualan produk digital: Lead baru, Menunggu pembayaran, Lunas & akses terkirim, Tidak jadi bayar, dan Refund. Tidak ada alamat, kurir, atau ongkir.</p>',
    'steps' => [
        ['text' => 'Buka <b>Pipeline</b>. Saat pembeli memastikan pembelian di chat, AI mencatat produk, jumlah, dan total dari katalog, lalu lead pindah ke <b>Menunggu pembayaran</b>. Bila rekening sudah diisi di <b>Pengaturan > Pembayaran</b>, instruksi bayar dikirim sistem.'],
        ['text' => 'Saat pembeli mengirim foto bukti transfer, lead ditandai <b>Bukti transfer masuk</b> dan admin serta agen pemilik lead mendapat notifikasi. AI tidak pernah menyatakan pembayaran diterima.'],
        ['text' => 'Cek dana di rekening, lalu buka chat di <b>Inbox</b> (menu <b>Aksi lain</b>) atau halaman lead dan klik <b>Pembayaran diterima</b>. Isi <b>Tautan akses</b> bila ada dan nyalakan <b>Kirim tautan akses ke pembeli lewat WhatsApp</b>. Lead pindah ke <b>Lunas & akses terkirim</b> dan tautannya tersimpan di lead.'],
        ['text' => 'Pembelian yang tidak dibayar pindah sendiri ke <b>Tidak jadi bayar</b> setelah batas jam di <b>Otomatisasi tahap</b>. Pindahkan ke <b>Refund</b> secara manual bila dana dikembalikan, dengan alasan gugur.'],
    ],
    'tips' => ['Di <b>Pelacakan iklan</b>, Menunggu pembayaran dikirim sebagai InitiateCheckout dan Lunas & akses terkirim sebagai Purchase setelah Anda menyalakannya.'],
    'perhatian' => ['Lunas hanya dicatat lewat tombol <b>Pembayaran diterima</b>. Jangan memindah lead ke Lunas sebelum dana dicek.'],
];
