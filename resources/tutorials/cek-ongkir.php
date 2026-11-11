<?php

return [
    'title' => 'Cek ongkir dan sumber ongkir',
    'group' => 'pengiriman',
    'order' => 20,
    'industries' => ['retail'],
    'admin' => true,
    'menu' => 'Pengaturan > Pengiriman > Asal dan kurir',
    'intro' => '<p>Ongkir yang disebut AI, yang muncul di tombol <b>Hitung ongkir</b> Inbox, dan yang ditagih ke pembeli dihitung dari satu sumber. AI tidak menyebut angka ongkir di luar hitungan ini.</p>',
    'steps' => [
        [
            'text' => 'Di <b>Asal dan kurir</b>, nyalakan <b>Hitung ongkir dari tabel zona</b>, isi alamat asal dan daftar <b>Kurir dan layanan</b>. Pilih <b>Kurir utama</b>: hanya kurir ini yang dihitung dan disebut ke pembeli, kecuali pembeli meminta kurir lain.',
            'alt' => 'Bagian Asal dan kurir dengan daftar kurir dan kurir utama',
            'shot' => ['page' => 'pengiriman', 'highlight' => ['.fi-fo-field:has-text("Kurir dan layanan") >> nth=0', '.fi-fo-field:has-text("Kurir utama")']],
        ],
        [
            'text' => 'Pilih <b>Sumber ongkir</b>: <b>Tabel zona</b> (tarif per kg yang Anda isi sendiri) atau <b>Everpro (cadangan tabel zona)</b>: tarif Everpro, sama dengan di Buat resi, bila akun Everpro aktif dan kode pos atau kelurahan tujuan diketahui. Bila tidak, atau Everpro lambat, dipakai tabel zona dan sumbernya dicatat di aktivitas lead.',
            'alt' => 'Pilihan Sumber ongkir: Tabel zona atau Everpro',
            'shot' => ['page' => 'pengiriman', 'highlight' => ['.fi-fo-field:has-text("Sumber ongkir")']],
        ],
        [
            'text' => 'Di <b>Zona dan tarif</b>, klik <b>Tambah zona</b>: beri nama, pilih provinsi atau kota, isi estimasi hari dan tarif per kg tiap kurir. Zona dicek dari atas: zona yang mencantumkan kota tujuan dipakai lebih dulu. Nyalakan <b>Konfirmasi admin (tanpa angka)</b> untuk zona yang ongkirnya harus dicek admin. Klik <b>Simpan</b>.',
            'alt' => 'Bagian Zona dan tarif dengan tombol Tambah zona',
            'shot' => ['page' => 'pengiriman', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Zona dan tarif"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Zona dan tarif"))']],
        ],
    ],
    'tips' => ['Berat dihitung dari berat produk, atau berat volume (P x L x T / pembagi, umumnya 6000) bila lebih besar. Produk tanpa berat memakai <b>Berat bawaan per produk</b>.'],
    'perhatian' => ['Bila tujuan tidak ada di tabel, atau zonanya bertanda Konfirmasi admin, AI tidak menyebut angka dan menyerahkan chat ke agen. Promo ongkir per produk dipotong setelah tarif dihitung.'],
];
