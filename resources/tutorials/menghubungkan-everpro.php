<?php

return [
    'title' => 'Menghubungkan Everpro',
    'group' => 'pengiriman',
    'order' => 10,
    'industries' => ['retail'],
    'admin' => true,
    'menu' => 'Pengaturan > Pengiriman > Pengiriman otomatis',
    'intro' => '<p>Dengan Everpro, toko membuat resi, mencetak label, meminta jemput paket, dan melacak kiriman langsung dari JITU LEAD. Setelah tersambung, menu <b>Pengiriman</b> muncul di samping.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Pengaturan > Pengiriman</b>, gulir ke <b>Pengiriman otomatis</b>, pilih <b>Penyedia</b> Everpro. Isi <b>Client key</b>, <b>Client secret</b>, dan <b>Callback key (x-everpro-key)</b> dari dashboard Everpro. Ketiganya tersimpan terenkripsi dan tidak ditampilkan lagi.',
            'alt' => 'Bagian Pengiriman otomatis dengan penyedia, mode sandbox, dan tiga kunci Everpro',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Pengiriman otomatis"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pengiriman otomatis"))']],
        ],
        [
            'text' => 'Klik <b>Simpan pengiriman otomatis</b>. URL callback lalu tampil di bagian yang sama: salin dan tempel di dashboard Everpro, <b>Pengaturan > Konfigurasi</b>, untuk callback AWB dan status, dengan key yang sama dengan Callback key. Klik <b>Uji koneksi</b>; saldo Everpro tampil di samping URL callback.',
            'alt' => 'URL callback, saldo Everpro, dan tombol Uji koneksi',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => 'button:has-text("Uji koneksi")']],
                'highlight' => ['.fi-sc-text:has-text("URL callback")', 'button:has-text("Uji koneksi")']],
        ],
        [
            'text' => 'Isi <b>Pengirim</b>: nama toko, nomor HP, dan alamat lengkap. Di <b>Lokasi asal (kelurahan)</b> ketik kode pos atau nama kelurahan lalu pilih (simpan kunci dulu agar pencarian berjalan).',
            'alt' => 'Bagian Pengirim dengan nama toko, HP, alamat, dan lokasi asal',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Pengirim"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Pengirim"))']],
        ],
        [
            'text' => 'Di <b>Kurir bawaan</b> isi kurir dan layanan (mis. JNE REG, dipakai bila pembeli belum memilih), <b>Cara serah paket</b>: Dijemput kurir (PICKUP) atau Antar ke agen (DROP), asuransi, dan <b>Peringatan saldo rendah</b>. Klik <b>Simpan pengiriman otomatis</b>.',
            'alt' => 'Bagian Kurir bawaan dengan cara serah paket dan peringatan saldo rendah',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Kurir bawaan"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Kurir bawaan"))']],
        ],
    ],
    'perhatian' => ['<b>Mode sandbox</b> hanya untuk uji coba. Matikan setelah uji coba berhasil untuk memakai production.'],
    'tips' => ['Pastikan saldo Everpro cukup untuk paket transfer (non-COD). Kiriman transfer yang ongkirnya melebihi saldo masuk tab <b>Menunggu saldo</b> dan dicoba lagi otomatis setelah saldo diisi.'],
];
