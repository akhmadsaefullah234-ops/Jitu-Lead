<?php

return [
    'title' => 'Tahap pipeline properti',
    'group' => 'penjualan',
    'order' => 20,
    'industries' => ['properti'],
    'menu' => 'Pipeline',
    'intro' => '<p>Papan tahap penjualan properti, dari lead baru sampai closing. Seret kartu lead dari satu kolom ke kolom lain. Setiap kolom menampilkan jumlah lead dan nilai transaksinya.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Pipeline</b>. Tahap bawaan: Lead baru, Dihubungi, Terkualifikasi, Jadwal survei, Sudah survei, Negosiasi, Booking, Closing, dan Gugur. Kartu bergaris merah berarti aksinya sudah terlambat. Di HP, satu tahap tampil sekali dan dipilih lewat tab di atas papan.',
            'alt' => 'Papan Pipeline properti dengan kolom Lead baru, Dihubungi, Terkualifikasi, dan Jadwal survei',
            'shot' => ['page' => 'pipeline', 'steps' => [['scroll' => '.jl-board']], 'highlight' => ['.jl-col >> nth=0', '.jl-col >> nth=1'],
                'mobile' => true, 'mobile_highlight' => ['.jl-tabs']],
        ],
        [
            'text' => 'Setiap tahap mengisi <b>aksi berikutnya</b> dan tenggatnya saat lead masuk. Bawaannya: Lead baru = hubungi lewat WhatsApp atau telepon (1 jam), Dihubungi = gali kebutuhan (48 jam), Terkualifikasi = kirim pilihan unit atau listing yang cocok (48 jam), Jadwal survei = konfirmasi H-1 sebelum survei, Sudah survei = kirim penawaran dan simulasi KPR (24 jam), Negosiasi = follow-up keputusan (72 jam), Booking = lengkapi berkas dan proses KPR (7 hari), Closing = minta referral (7 hari).',
            'alt' => 'Kartu lead di Pipeline dengan aksi berikutnya dan tenggatnya',
            'shot' => ['page' => 'pipeline', 'steps' => [['scroll' => '.jl-board']], 'highlight' => ['.jl-card >> nth=0']],
        ],
        [
            'text' => 'Saring papan dengan kotak cari, tombol cepat (<b>Hot</b>, <b>Aksi hari ini</b>, <b>Terlambat</b>), pilihan agen, periode, dan properti.',
            'alt' => 'Kotak cari dan tombol saring cepat di atas Pipeline properti',
            'shot' => ['page' => 'pipeline', 'highlight' => ['.jl-bar']],
        ],
        [
            'text' => 'Klik <b>Pindah tahap</b> di kartu (atau seret kartunya), pilih tahap, lalu <b>Pindahkan</b>. Tiga tahap meminta data dulu: <b>Jadwal survei</b> (tanggal, jam, dan lokasi), <b>Booking</b> dan <b>Closing</b> (unit dan nilai transaksi), serta <b>Gugur</b> (alasan gugur). Lihat bab-bab berikutnya.',
            'alt' => 'Jendela Pindahkan lead dengan pilihan Tahap',
            'shot' => ['page' => 'pipeline', 'steps' => [['click' => '.jl-card >> nth=0 >> button:has-text("Pindah tahap")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
    ],
    'tips' => [
        'Lead masuk dari Iklan Meta, Portal properti, Formulir web, Referral, Pameran, dan Walk-in. Daftar sumber ini bisa diubah di Pengaturan > Sumber lead.',
        'Nama tahap, urutan, aksi berikutnya, dan tenggatnya bisa diubah di Pengaturan > Tahap pipeline (lihat bab Tahap pipeline, sumber lead, dan alasan gugur).',
    ],
];
