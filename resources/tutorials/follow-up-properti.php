<?php

return [
    'title' => 'Follow-up properti',
    'group' => 'percakapan',
    'order' => 61,
    'industries' => ['properti'],
    'admin' => true,
    'menu' => 'Follow-up otomatis',
    'intro' => '<p>Agensi properti mendapat tiga follow-up bawaan untuk tahap awal penjualan. Semuanya dibuat <b>nonaktif</b> sampai Anda memeriksa kalimatnya. Cara kerja follow-up dijelaskan di bab Follow-up otomatis.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Follow-up otomatis</b>. Rangkaian bawaan: <b>Sapaan H+1</b> (tahap Lead baru), <b>Tanya kebutuhan H+3</b> (Dihubungi), dan <b>Tawarkan survei H+7</b> (Terkualifikasi). Pada mode <b>Otomatis</b>, follow-up umum selalu dipakai untuk agensi properti, berapa pun jumlah properti aktifnya.',
            'alt' => 'Daftar Follow-up otomatis dengan Sapaan H+1, Tanya kebutuhan H+3, dan Tawarkan survei H+7',
            'shot' => ['page' => 'follow-up-rules', 'highlight' => ['.fi-callout >> nth=0', '.fi-ta-table']],
        ],
        [
            'text' => 'Klik nama aturan untuk memeriksa isinya. Pakai variabel <b>{nama}</b> (nama lead), <b>{agen}</b> (agen pemilik lead), <b>{agensi}</b> (nama agensi), dan <b>{properti}</b> (properti yang diminati lead). Ubah <b>Kirim pada</b> dan <b>Jam kirim (WIB)</b> bila perlu, lalu simpan.',
            'alt' => 'Jendela aturan follow-up Sapaan H+1 dengan isi pesan dan variabel',
            'shot' => ['page' => 'follow-up-rules', 'steps' => [['click' => '.fi-ta-row:has-text("Sapaan H+1") >> text="Sapaan H+1"'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-fo-field:has-text("Isi pesan")', '.fi-modal-window:visible .fi-fo-field:has-text("Kirim pada")']],
        ],
        [
            'text' => 'Nyalakan sakelar <b>Aktif</b> di baris aturan yang kalimatnya sudah benar. Follow-up hanya terkirim ke lead yang masih berada di tahap itu pada hari dan jam kirimnya.',
            'alt' => 'Sakelar Aktif di daftar follow-up',
            'shot' => ['page' => 'follow-up-rules', 'highlight' => ['.fi-ta-row:has-text("Sapaan H+1") .fi-ta-toggle, .fi-ta-row:has-text("Sapaan H+1") button[role=switch]']],
        ],
    ],
    'tips' => ['Bila lead belum punya properti diminati, {properti} diisi Lokasi incaran lead; bila itu juga kosong, diisi "properti yang Anda tanyakan".'],
];
