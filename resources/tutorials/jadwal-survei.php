<?php

return [
    'title' => 'Jadwal survei',
    'group' => 'penjualan',
    'order' => 22,
    'industries' => ['properti'],
    'menu' => 'Pipeline',
    'intro' => '<p>Saat lead siap melihat lokasi, pindahkan ke tahap <b>Jadwal survei</b>. Tahap ini wajib diisi jadwal dan lokasi survei, dan aksi berikutnya otomatis diatur untuk konfirmasi H-1.</p>',
    'steps' => [
        [
            'text' => 'Di <b>Pipeline</b>, klik <b>Pindah tahap</b> di kartu lead (atau seret kartunya ke kolom Jadwal survei), lalu pilih tahap <b>Jadwal survei</b>.',
            'alt' => 'Jendela Pindahkan lead dengan tahap Jadwal survei dipilih',
            'shot' => ['page' => 'pipeline', 'steps' => [['click' => '.jl-card:has-text("Erik Contoh") >> button:has-text("Pindah tahap")'], ['waitFor' => '.fi-modal-window:visible'],
                ['select' => ['.fi-modal-window:visible select', 'Jadwal survei']], ['waitFor' => '.fi-modal-window:visible .fi-fo-field:has-text("Lokasi survei")']],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has-text("Tahap")']],
        ],
        [
            'text' => 'Isi <b>Jadwal survei</b> (tanggal dan jam, harus setelah sekarang) dan <b>Lokasi survei</b> (terisi nama properti yang diminati; ubah bila perlu), lalu klik <b>Pindahkan</b>. Bila ada yang kosong, muncul pesan "Isi tanggal dan jam survei", "Jadwal survei harus setelah sekarang", atau "Isi lokasi survei".',
            'alt' => 'Isian Jadwal survei dan Lokasi survei di jendela Pindahkan',
            'shot' => ['page' => 'pipeline', 'steps' => [['click' => '.jl-card:has-text("Erik Contoh") >> button:has-text("Pindah tahap")'], ['waitFor' => '.fi-modal-window:visible'],
                ['select' => ['.fi-modal-window:visible select', 'Jadwal survei']], ['waitFor' => '.fi-modal-window:visible .fi-fo-field:has-text("Lokasi survei")'],
                ['fill' => ['.fi-modal-window:visible input[type="datetime-local"]', '2026-11-21T10:00']], ['fill' => ['.fi-modal-window:visible .fi-fo-field:has-text("Lokasi survei") input', 'Rumah contoh Cluster Contoh Asri']]],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has(input[type="datetime-local"])', '.fi-modal-window:visible .fi-fo-field:has-text("Lokasi survei")']],
        ],
        [
            'text' => 'Aksi berikutnya lead menjadi <b>Konfirmasi H-1 sebelum survei</b>, dengan tenggat sehari sebelum jadwal (atau sekarang, bila survei kurang dari sehari lagi). Kartu lead menampilkan tenggat itu, dan lead muncul di <b>Aksi hari ini</b> pada harinya.',
            'alt' => 'Kartu lead di kolom Jadwal survei dengan aksi Konfirmasi H-1 sebelum survei',
            'shot' => ['page' => 'pipeline', 'steps' => [['scroll' => '.jl-col:has-text("Fitri Contoh")']], 'highlight' => ['.jl-card:has-text("Fitri Contoh")']],
        ],
        [
            'text' => 'Setelah survei berlangsung, pindahkan lead ke <b>Sudah survei</b>. Aksi berikutnya: kirim penawaran dan simulasi KPR dalam 24 jam.',
        ],
    ],
    'tips' => [
        'Dasbor menghitung Survei terjadwal (survei yang tanggalnya jatuh di periode itu) dan Survei terlaksana (lead yang masuk Sudah survei di periode itu).',
        'Tahap ini bisa memicu event Meta Schedule (bawaan untuk properti, mati sampai dinyalakan di Pelacakan iklan) dan label WhatsApp Business Prospek (uji coba).',
    ],
    'perhatian' => ['Jadwal dan lokasi survei hanya diminta saat lead masuk tahap ini. Untuk mengganti jadwal, pindahkan lead lagi ke Jadwal survei dengan tanggal baru.'],
];
