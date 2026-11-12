<?php

return [
    'title' => 'Inbox',
    'group' => 'percakapan',
    'order' => 20,
    'menu' => 'Inbox',
    'intro' => '<p>Semua chat WhatsApp lead di satu tempat. Agen hanya melihat chat lead miliknya. Daftar diperbarui sendiri tiap 10 detik.</p>',
    'steps' => [
        [
            'text' => 'Pilih percakapan di daftar kiri. Tiap baris menampilkan nama, nomor, pesan terakhir, agen pemilik, dan {item} yang ditanyakan. Angka merah adalah pesan belum dibaca.',
            'alt' => 'Daftar percakapan Inbox dengan nama lead, pesan terakhir, agen, dan produk',
            'shot' => ['page' => 'inbox', 'highlight' => ['.wa-item >> nth=0'], 'mobile' => true],
        ],
        [
            'text' => 'Di atas chat terlihat nama, tahap, dan {item} lead. Klik nama untuk membuka atau melipat detail lead (nomor, tahap, siapa yang membalas, dan iklan asal). Klik {item} untuk menggantinya.',
            'alt' => 'Chat terbuka dengan nama lead, chip tahap, dan chip produk di bagian atas',
            'shot' => ['page' => 'inbox', 'steps' => [['click' => '.wa-item:has-text("Budi Contoh")'], ['waitFor' => '.wa-name']],
                'highlight' => ['.wa-name', '.wa-chip', '.wa-chip-prod'],
                'mobile' => true],
        ],
        [
            'text' => 'Ketik balasan di kolom <b>Tulis pesan</b>. Enter membuat baris baru; tekan tombol kirim atau <b>Ctrl+Enter</b> (Cmd+Enter di Mac) untuk mengirim. Tombol klip kertas (<b>Lampiran</b>) mengirim gambar atau dokumen dari pustaka media atau dari komputer.',
            'alt' => 'Kolom tulis pesan dengan tombol lampiran dan tombol kirim',
            'shot' => ['page' => 'inbox', 'steps' => [['click' => '.wa-item:has-text("Budi Contoh")'], ['waitFor' => '.wa-compose textarea']],
                'highlight' => ['.wa-compose']],
        ],
        [
            'text' => 'Menu <b>Aksi lain</b> (tombol ⋯) berisi aksi untuk lead ini: Pembayaran diterima, Buat resi, Hubungkan resi, Lacak paket, Cetak label, Batalkan kiriman, Nomor resi, Hitung ongkir, dan Buka lead. Yang tampil hanya aksi yang sesuai keadaan lead.',
            'alt' => 'Menu Aksi lain di Inbox terbuka',
            'shot' => ['page' => 'inbox', 'steps' => [['click' => '.wa-item:has-text("Budi Contoh")'], ['click' => '.wa-more button'], ['waitFor' => '.fi-dropdown-panel:visible']],
                'highlight' => ['.fi-dropdown-panel:visible']],
        ],
        [
            'text' => 'Bila AI menyerahkan chat, kotak merah <b>AI menyerahkan chat ini ke Anda</b> tampil dengan alasannya. Balas pembeli, lalu klik <b>Tutup</b>. Pada mode AI menyiapkan, kotak <b>Saran balasan AI</b> tampil dengan tombol <b>Pakai saran ini</b> dan <b>Buang</b>.',
            'alt' => 'Kotak AI menyerahkan chat ini ke Anda dengan alasan di atas kolom pesan',
            'shot' => ['page' => 'inbox', 'steps' => [['click' => '.wa-item:has-text("Eka Contoh")'], ['waitFor' => '.ai-draft']],
                'highlight' => ['.ai-draft']],
        ],
        [
            'text' => 'Lencana hijau <b>OK</b> berarti pesanan sudah OK dan perlu ditangani di menu Pengiriman. Pilih <b>Closing</b> di atas daftar untuk melihat chat itu saja.',
            'alt' => 'Lencana OK di daftar chat dan filter Closing',
            'shot' => ['page' => 'inbox', 'highlight' => ['.wa-filter', '.wa-item:has(.wa-ok) .wa-ok']],
        ],
    ],
    'tips' => [
        'AI berhenti sendiri saat Anda mengetik di chat, dan aktif lagi 1 menit setelah Anda berhenti mengetik. Setelah Anda mengirim pesan, AI menunggu 5 menit, karena balasan pembeli kemungkinan untuk Anda. Selama jeda, tertulis "AI dijeda sampai ..." di atas kolom pesan.',
        'Di bawah pesan ada keterangan kecil seperti "Sambutan otomatis" atau "Status kiriman". Voice note bisa diputar dan ditranskripsikan, dan bukti transfer yang masuk diberi titik "perlu dicek" di samping nama.',
    ],
];
