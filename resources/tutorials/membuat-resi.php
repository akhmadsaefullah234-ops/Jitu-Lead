<?php

return [
    'title' => 'Membuat resi',
    'group' => 'pengiriman',
    'order' => 30,
    'industries' => ['retail'],
    'menu' => 'Tombol Pengiriman di halaman lead, menu ⋯ di Inbox, atau aksi massal di daftar Lead',
    'intro' => '<p>Membuat resi Everpro untuk satu lead tanpa membuka dashboard Everpro. Nomor resi yang terbit dikirim ke pembeli lewat WhatsApp.</p>',
    'steps' => [
        [
            'text' => 'Buka lead, klik <b>Pengiriman</b> lalu <b>Buat resi</b>. Di Inbox, menu yang sama ada di tombol ⋯ di atas chat. Untuk banyak lead sekaligus, centang di daftar Lead lalu pilih <b>Buat resi</b> di aksi massal.',
            'alt' => 'Menu Pengiriman di halaman lead dengan pilihan Buat resi',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Dedi Contoh"'], ['click' => '.fi-header button:has-text("Pengiriman")'], ['waitFor' => '.fi-dropdown-panel:visible']],
                'highlight' => ['.fi-header button:has-text("Pengiriman")', '.fi-dropdown-panel:visible button:has-text("Buat resi")']],
        ],
        [
            'text' => 'Pilih <b>Cara bayar</b>: COD (bayar di tempat) atau Transfer (sudah lunas). Pilihan ini wajib dan tidak pernah ditebak; bila chat menyebutnya, saran tampil di bawahnya. Bila alamat lead belum lengkap, isi di bagian <b>Alamat penerima</b> atau klik <b>Ambil dari chat</b>.',
            'alt' => 'Jendela Buat resi otomatis dengan pilihan cara bayar',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Dedi Contoh"'], ['click' => '.fi-header button:has-text("Pengiriman")'], ['click' => '.fi-dropdown-panel:visible button:has-text("Buat resi")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has-text("Cara bayar")']],
        ],
        [
            'text' => 'Pilih kurir dan layanan dari daftar tarif Everpro. Periksa ongkir, ongkir yang ditagih ke pembeli, dan total COD. Bila ada kesepakatan lain di chat, ubah <b>Rincian paket</b>: ongkir ditagih, isi paket, berat, ukuran, asuransi, dan catatan untuk kurir.',
            'alt' => 'Pilihan kurir dan layanan serta Rincian paket di jendela Buat resi',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Dedi Contoh"'], ['click' => '.fi-header button:has-text("Pengiriman")'], ['click' => '.fi-dropdown-panel:visible button:has-text("Buat resi")'], ['waitFor' => '.fi-modal-window:visible'],
                ['click' => '.fi-modal-window:visible label:has-text("COD (bayar di tempat)")'], ['wait' => 1500], ['scroll' => '.fi-modal-window:visible .fi-fo-field:has-text("Kurir dan layanan")']],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has-text("Kurir dan layanan")']],
        ],
        [
            'text' => 'Klik <b>Buat resi</b> di bawah jendela. Bila tombol belum bisa diklik, alasannya tertulis tepat di atasnya. Setelah itu kiriman ada di menu <b>Pengiriman</b>, tab <b>Menunggu resi</b>, sampai nomor resi terbit; nomor itu dikirim ke pembeli sekali dan label PDF disimpan otomatis.',
            'alt' => 'Tombol Buat resi di bawah jendela',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Dedi Contoh"'], ['click' => '.fi-header button:has-text("Pengiriman")'], ['click' => '.fi-dropdown-panel:visible button:has-text("Buat resi")'], ['waitFor' => '.fi-modal-window:visible'],
                ['click' => '.fi-modal-window:visible label:has-text("COD (bayar di tempat)")'], ['wait' => 1500], ['scroll' => '.fi-modal-window:visible .fi-modal-footer']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-footer button:has-text("Buat resi")']],
        ],
    ],
    'penting' => ['Untuk pembayaran transfer yang belum ada bukti bayar atau tanda lunas di lead, jendela meminta centang <b>Saya sudah memastikan transfer pembeli masuk</b>. Centang hanya setelah uangnya benar-benar masuk.'],
    'tips' => ['Bila resi gagal karena saldo atau alamat, perbaiki lalu klik <b>Coba lagi kiriman</b> di menu Pengiriman lead. Rincian yang tersimpan dipakai lagi dan tidak membuat kiriman ganda.'],
];
