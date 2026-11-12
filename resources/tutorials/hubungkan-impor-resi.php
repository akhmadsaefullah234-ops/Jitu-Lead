<?php

return [
    'title' => 'Hubungkan dan impor resi',
    'group' => 'pengiriman',
    'order' => 80,
    'industries' => ['retail'],
    'menu' => 'Pengiriman > Hubungkan resi di lead, dan Impor resi di daftar Lead',
    'intro' => '<p>Untuk resi yang dibuat di luar JITU LEAD, misalnya langsung di Everpro. Resi itu ikut dilacak, tetapi tidak bisa dibatalkan dari JITU LEAD.</p>',
    'steps' => [
        [
            'text' => 'Satu lead: buka lead, klik <b>Pengiriman</b> lalu <b>Hubungkan resi</b>. Isi nomor resi dan kurir (bawaan JNE). <b>Kirim nomor resi ke pembeli sekarang</b> bawaannya mati; <b>Bila paket sudah diterima, pindahkan lead ke Selesai</b> bawaannya nyala.',
            'alt' => 'Jendela Hubungkan resi dengan nomor resi, kurir, dan dua pilihan',
            'shot' => ['page' => 'leads', 'steps' => [['click' => 'text="Dedi Contoh"'], ['click' => '.fi-header button:has-text("Pengiriman")'], ['click' => '.fi-dropdown-panel:visible button:has-text("Hubungkan resi")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Banyak sekaligus: di daftar <b>Lead</b> klik <b>Impor resi</b>, lalu <b>Unggah file ekspor</b> (CSV atau XLSX dari dashboard penyedia, maksimal 500 baris, berisi nomor resi, nama, dan HP penerima).',
            'alt' => 'Halaman Impor resi dengan tombol Unggah file ekspor',
            'shot' => ['page' => 'import-resi', 'highlight' => ['.fi-header button:has-text("Unggah file ekspor")']],
        ],
    ],
    'tips' => [
        'Setelah unggah, periksa tebakan kolom dan pencocokan. Baris <b>Cocok</b> sudah dipilih; baris lain pilih lead-nya atau biarkan Lewati. Klik <b>Simpan yang dipilih</b>.',
        'Nomor berawalan TRD adalah nomor order Everpro, bukan resi. JITU LEAD mencari nomor resi aslinya sendiri.',
    ],
];
