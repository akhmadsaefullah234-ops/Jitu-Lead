<?php

use App\Filament\Resources\Leads\LeadResource;

return [
    'title' => 'Daftar Lead',
    'group' => 'penjualan',
    'order' => 10,
    'menu' => 'Lead',
    'intro' => '<p>Semua lead dalam satu tabel. Agen hanya melihat lead miliknya.</p>',
    'pages' => [LeadResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>Lead</b>. Cari berdasarkan nama atau nomor di kotak <b>Cari</b>. Nomor tersimpan dalam format +62, jadi ketik angka setelah 0 di depan, misalnya 812345 untuk 0812345....',
            'alt' => 'Daftar Lead dengan kotak Cari dan kolom nama, telepon, tahap, minat, agen',
            'shot' => ['page' => 'leads', 'highlight' => ['.fi-ta-search-field', '.fi-ta-row >> nth=0']],
        ],
        [
            'text' => 'Klik ikon filter untuk menyaring: tanggal masuk, {item} (termasuk "Tanpa {item}"), asal lead, iklan, masuk tahap pada periode, tahap, minat, agen, aksi terlambat, belum ditanggapi, dan kesiapan resi. Klik <b>Terapkan filter</b>.',
            'alt' => 'Panel filter daftar Lead terbuka',
            'shot' => ['page' => 'leads', 'steps' => [['click' => '.fi-ta-filters-dropdown .fi-dropdown-trigger button, .fi-ta-filters-dropdown > div > button'], ['waitFor' => '.fi-dropdown-panel:visible']],
                'highlight' => ['.fi-ta-filters-dropdown .fi-dropdown-trigger', '.fi-dropdown-panel:visible']],
        ],
        [
            'text' => 'Centang beberapa lead, lalu buka <b>Tindakan</b> untuk aksi massal: Pindahkan ke agen berikutnya, Buat resi, Cetak label, atau hapus.',
            'alt' => 'Dua lead dicentang dan menu Tindakan berisi aksi massal',
            'shot' => ['page' => 'leads', 'steps' => [['click' => '.fi-ta-record-checkbox >> nth=0'], ['click' => '.fi-ta-record-checkbox >> nth=1'], ['click' => 'button:has-text("Tindakan")'], ['waitFor' => '.fi-dropdown-panel:visible']],
                'highlight' => ['.fi-dropdown-panel:visible']],
        ],
        [
            'text' => '<b>Ekspor CSV</b> mengunduh lead sesuai filter yang aktif. <b>Impor resi</b> menghubungkan resi dari berkas Everpro, dan <b>Buat lead</b> menambah lead secara manual.',
            'alt' => 'Tombol Impor resi, Ekspor CSV, dan Buat lead di atas daftar Lead',
            'shot' => ['page' => 'leads', 'highlight' => ['.fi-header-actions-ctn, .fi-header .fi-ac']],
        ],
        [
            'text' => 'Klik nama lead untuk membuka halamannya: kontak, detail pesanan, perjalanan paket, dan tindak lanjut. Tombol <b>Chat WhatsApp</b> membuka chat lead di Inbox.',
            'alt' => 'Halaman ubah lead dengan tombol Chat WhatsApp dan Pengiriman',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Gilang Contoh"'], ['waitFor' => 'a:has-text("Chat WhatsApp"), button:has-text("Chat WhatsApp")']],
                'highlight' => [':is(a,button):has-text("Chat WhatsApp")', '.fi-header :is(a,button):has-text("Pengiriman")']],
        ],
    ],
    'tips' => ['Kolom Resi dan filter <b>Kesiapan resi</b> (Siap resi atau Perlu dilengkapi) menunjukkan lead mana yang datanya masih kurang untuk dibuatkan resi.'],
    'perhatian' => ['Saat menambah lead manual dengan nomor atau email yang sudah dipakai, muncul peringatan berisi nama lead yang sama.'],
];
