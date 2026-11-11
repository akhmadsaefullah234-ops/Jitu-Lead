<?php

return [
    'title' => 'Menggali kebutuhan pembeli',
    'group' => 'penjualan',
    'order' => 21,
    'industries' => ['properti'],
    'menu' => 'Lead, lalu klik nama lead',
    'intro' => '<p>Di tahap <b>Dihubungi</b>, aksi bawaannya adalah menggali kebutuhan: tipe, lokasi, budget, dan cara bayar. Catat jawabannya di data lead, supaya Anda dan tim bisa memilih unit yang cocok di tahap Terkualifikasi.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Lead</b>, lalu klik nama lead. Bagian <b>Kebutuhan properti</b> berisi Kebutuhan (Beli atau Sewa), Tipe properti, Lokasi incaran, Cara bayar (KPR, Tunai keras, atau Tunai bertahap), Budget minimum, Budget maksimum, dan Properti diminati.',
            'alt' => 'Halaman lead dengan bagian Kebutuhan properti: kebutuhan, tipe properti, lokasi incaran, cara bayar, budget, dan properti diminati',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Candra Contoh"'], ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Kebutuhan properti"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Kebutuhan properti"))'], 'mobile' => true],
        ],
        [
            'text' => 'Isi <b>Properti diminati</b> dengan proyek atau listing yang ditanyakan. AI menjawab chat lead itu berdasarkan pilihan ini, dan pesan sambutan serta follow-up menyebut namanya di {properti}.',
            'alt' => 'Kolom Properti diminati pada halaman lead',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Candra Contoh"'], ['scroll' => '.fi-fo-field:has-text("Properti diminati")']],
                'highlight' => ['.fi-fo-field:has-text("Properti diminati")', '.fi-fo-field:has-text("Budget maksimum")']],
        ],
        [
            'text' => 'Di bagian <b>Tindak lanjut</b>, perbarui <b>Tingkat minat</b> (Hot, Warm, Cold), <b>Aksi berikutnya</b>, dan <b>Tenggat aksi</b>, lalu klik <b>Simpan perubahan</b>. Setelah kebutuhannya jelas, pindahkan lead ke <b>Terkualifikasi</b> di Pipeline.',
            'alt' => 'Bagian Tindak lanjut dengan tingkat minat, aksi berikutnya, dan tenggat aksi',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Candra Contoh"'], ['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Tindak lanjut"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Tindak lanjut"))']],
        ],
    ],
    'tips' => [
        'Jawaban dari formulir Minat properti (budget dan rencana cara bayar) tersimpan di bagian Jawaban formulir, bukan di kolom Budget dan Cara bayar. Salin ke kolom lead setelah dikonfirmasi di chat.',
        'Admin bisa menyembunyikan kolom ini lewat Lead > Pengaturan agensi > Tampilkan kolom khusus jenis bisnis. Data yang sudah tersimpan tidak dihapus.',
    ],
];
