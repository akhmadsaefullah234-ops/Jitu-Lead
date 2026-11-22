<?php

return [
    'title' => 'Pembayaran otomatis (iPaymu)',
    'group' => 'penjualan',
    'order' => 31,
    'industries' => ['digital'],
    'admin' => true,
    'menu' => 'Pengaturan > Pembayaran otomatis',
    'intro' => '<p>Pembeli membayar lewat QRIS atau virtual account di akun iPaymu Anda sendiri. Lunas dicatat otomatis setelah iPaymu menyatakan pembayaran berhasil, lalu tautan akses dikirim ke pembeli.</p>',
    'steps' => [
        ['text' => 'Buka <b>Pembayaran otomatis</b>, isi <b>VA iPaymu</b> dan <b>API Key</b> dari dasbor iPaymu, biarkan <b>Mode sandbox</b> menyala untuk mencoba, lalu nyalakan <b>Aktifkan pembayaran otomatis</b> dan klik <b>Simpan</b>. Kunci disimpan terenkripsi dan tidak ditampilkan lagi.'],
        ['text' => 'Buka <b>Katalog</b> dan isi <b>Harga</b> serta <b>Tautan akses produk</b> untuk setiap produk. Pembeli tidak pernah melihat tautan asli: ia menerima tautan bertanda tangan yang berlaku beberapa hari dan hanya untuk dirinya.'],
        ['text' => 'Saat pembeli memastikan pembelian di chat, sistem mengirim tautan bayar QRIS/VA lewat WhatsApp. Tautan bayar juga bisa dikirim dari <b>Inbox</b> (menu <b>Aksi lain</b> > <b>Kirim tautan bayar</b>), atau dari formulir dengan aksi <b>Arahkan ke halaman bayar</b>.'],
        ['text' => 'Begitu iPaymu menyatakan lunas, lead pindah ke <b>Lunas & akses terkirim</b> dan pembeli menerima tautan akses lewat WhatsApp dan email. Pengingat dikirim sekali, 3 jam sebelum tautan bayar kedaluwarsa.'],
    ],
    'tips' => ['Pakai mode sandbox dulu dan lakukan satu pembayaran uji sebelum mematikannya.'],
    'perhatian' => ['Refund diproses manual: kembalikan dana dari dasbor iPaymu atau rekening Anda, lalu pindahkan lead ke tahap Refund.'],
];
