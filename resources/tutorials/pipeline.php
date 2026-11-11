<?php

use App\Filament\Pages\Pipeline;

return [
    'title' => 'Pipeline',
    'group' => 'penjualan',
    'order' => 20,
    'industries' => ['retail'],
    'menu' => 'Pipeline',
    'intro' => '<p>Papan tahap penjualan. Seret kartu lead dari satu kolom ke kolom lain. Setiap kolom menampilkan jumlah lead, nilai, dan langkah yang disarankan untuk tahap itu.</p>',
    'pages' => [Pipeline::class],
    'steps' => [
        [
            'text' => 'Buka <b>Pipeline</b>. Tahap toko: Tanya produk, Order masuk, Menunggu pembayaran, Dikemas & dikirim, Selesai, dan Batal. Toko yang terhubung ke Everpro melihat Dikemas & dikirim sebagai dua kolom, <b>Dikemas</b> dan <b>Dikirim</b> (lihat langkah 5). Kartu bergaris merah berarti aksinya sudah terlambat. Di HP, satu tahap tampil sekali dan dipilih lewat tab di atas papan.',
            'alt' => 'Papan Pipeline dengan kolom Tanya produk, Order masuk, Menunggu pembayaran, dan seterusnya',
            'shot' => ['page' => 'pipeline', 'steps' => [['scroll' => '.jl-board']], 'highlight' => ['.jl-col >> nth=0', '.jl-col >> nth=1'],
                'mobile' => true, 'mobile_highlight' => ['.jl-tabs']],
        ],
        [
            'text' => 'Saring papan dengan kotak cari, tombol cepat (Hot, Aksi hari ini, Terlambat, Perlu resi, Siap cetak, Gagal antar), dan pilihan agen.',
            'alt' => 'Kotak cari dan tombol saring cepat di atas Pipeline',
            'shot' => ['page' => 'pipeline', 'highlight' => ['.jl-bar']],
        ],
        [
            'text' => 'Pilih periode (bawaan <b>Semua waktu</b>). Setelah periode dipilih, pilih dasarnya: <b>Tanggal lead masuk</b> atau <b>Tanggal masuk tahap</b>. Saring juga per {item}. Jumlah dan nilai tiap kolom mengikuti pilihan ini.',
            'alt' => 'Pemilih periode Pipeline dengan pilihan Tanggal lead masuk atau Tanggal masuk tahap',
            'shot' => ['page' => 'pipeline', 'steps' => [['select' => ['.jl-period select', '30d']], ['waitFor' => ':is(label,button):has-text("Tanggal masuk tahap")']],
                'highlight' => ['.jl-period']],
        ],
        [
            'text' => 'Klik <b>Pindah tahap</b> di kartu (atau seret kartunya), pilih tahap, lalu <b>Pindahkan</b>. Tahap <b>Batal</b> meminta alasan gugur, dan tahap <b>Selesai</b> meminta pesanan dan nilai transaksi.',
            'alt' => 'Jendela Pindahkan lead dengan pilihan Tahap',
            'shot' => ['page' => 'pipeline', 'steps' => [['click' => '.jl-card >> nth=0 >> button:has-text("Pindah tahap")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Admin bisa mengatur <b>Pengaturan Pipeline > Pisahkan Dikemas dan Dikirim</b> (bawaan menyala bila akun terhubung ke Everpro): tahap Dikemas & dikirim tampil sebagai dua kolom menurut resi (Dikemas: belum ada resi atau belum dicetak; Dikirim: resi sudah dicetak). Tahap lead sendiri tidak berubah.',
            'alt' => 'Jendela Pengaturan Pipeline dengan sakelar Pisahkan Dikemas dan Dikirim',
            'shot' => ['page' => 'pipeline', 'steps' => [['click' => 'button:has-text("Pengaturan Pipeline")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
    ],
    'tips' => ['Memindahkan lead ke tahap tertentu bisa memicu hal lain: event konversi ke Meta, label WhatsApp, follow-up tahap itu, dan resi otomatis.'],
];
