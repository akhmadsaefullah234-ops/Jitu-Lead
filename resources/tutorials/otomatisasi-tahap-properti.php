<?php

return [
    'title' => 'Otomatisasi tahap',
    'group' => 'penjualan',
    'order' => 30,
    'industries' => ['properti'],
    'admin' => true,
    'menu' => 'Pengaturan > Otomatisasi tahap',
    'intro' => '<p>Sembilan aturan yang menggerakkan lead properti sendiri mengikuti percakapan: Dihubungi saat klien membalas, Terkualifikasi saat kebutuhannya tercatat, Sudah survei setelah jadwal lewat, dan Gugur bila klien diam. Tugas agen ikut rapi: yang sudah dibalas AI selesai sendiri, yang butuh manusia muncul dengan alasannya. Setiap perpindahan tercatat di aktivitas lead sebagai "Pindah otomatis" dan memicu follow-up, event iklan, dan label WhatsApp tahap barunya.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Otomatisasi tahap</b>. Agensi baru mendapati aturannya sudah menyala, dengan tombol <b>Simpan</b> dan <b>Terapkan ke lead yang ada</b>. Agensi yang dibuat sebelum fitur ini melihat semua aturan mati dan tombol <b>Nyalakan</b> di tempat yang sama: klik, lalu konfirmasi. Semua aturan menyala dengan nilai bawaannya kecuali pesan terima kasih setelah survei.',
            'alt' => 'Halaman Otomatisasi tahap properti dengan tombol Nyalakan',
            'shot' => ['page' => 'otomatisasi-tahap', 'highlight' => ['.fi-header button:has-text("Simpan")', '.fi-header button:has-text("Terapkan ke lead yang ada")']],
        ],
        [
            'text' => 'Aturan 1 dan 2: Lead baru pindah ke <b>Dihubungi</b> hanya bila klien membalas setelah pesan pertama kita, dan ke <b>Terkualifikasi</b> saat tujuan plus domisili, budget, atau cara bayar tercatat dan klien sudah membalas minimal 2 kali. Aturan 3: klien yang membatalkan survei lewat chat kembali ke Terkualifikasi dengan tugas menawarkan jadwal baru.',
            'alt' => 'Aturan 1 sampai 3 dengan sakelar Aktif',
            'shot' => ['page' => 'otomatisasi-tahap', 'highlight' => ['.fi-section >> nth=0', '.fi-section >> nth=1']],
        ],
        [
            'text' => 'Aturan 4 dan 5: lead pindah ke <b>Sudah survei</b> beberapa jam (bawaan 3) setelah jam survei, dengan tugas "Kirim penawaran dan simulasi"; pesan terima kasih bisa dinyalakan dan diubah isinya. Aturan 6: klien yang diam setelah follow-up selesai digugurkan dengan alasan "Tidak ada respons" (bawaan 14 hari untuk Lead baru, 10 hari untuk lainnya). Aturan 7: klien Gugur yang menulis lagi kembali ke Dihubungi.',
            'alt' => 'Aturan survei, pesan terima kasih, dan klien diam dengan isian jam dan hari',
            'shot' => ['page' => 'otomatisasi-tahap', 'steps' => [['scroll' => '.fi-section:has-text("4. Jadwal survei lewat")']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("4. Jadwal survei lewat"))', '.fi-section:has(.fi-section-header-heading:has-text("6. Klien diam"))']],
        ],
        [
            'text' => 'Aturan 8: klien yang menulis siap booking diserahkan ke agen dengan tugas "Proses booking"; Booking dan Closing tetap diisi agen. Aturan 9: tugas "Hubungi lewat WhatsApp atau telepon" selesai sendiri saat balasan pertama keluar, "Gali kebutuhan" selesai saat tujuan klien tercatat, dan chat yang diserahkan AI menjadi tugas agen beserta alasannya. Klik <b>Simpan</b>.',
            'alt' => 'Aturan siap booking dan tugas mengikuti percakapan',
            'shot' => ['page' => 'otomatisasi-tahap', 'steps' => [['scroll' => '.fi-section:has-text("9. Tugas mengikuti")']], 'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("9. Tugas mengikuti"))']],
        ],
        [
            'text' => 'Untuk lead yang sudah ada, klik <b>Terapkan ke lead yang ada</b>. Jendela yang muncul mendaftar setiap perubahan (lead, tahap tujuan atau tugas yang selesai, dan alasannya) sebelum apa pun diubah. Klik <b>Terapkan</b> bila daftarnya sesuai.',
            'alt' => 'Pratinjau perubahan untuk lead yang sudah ada',
            'shot' => ['page' => 'otomatisasi-tahap', 'steps' => [['click' => 'button:has-text("Terapkan ke lead yang ada")'], ['waitFor' => '.fi-modal-window:visible']], 'highlight' => ['.fi-modal-window:visible']],
        ],
    ],
    'tips' => [
        'Berapa lead yang digerakkan tiap tahap, dan event serta label yang ikut terkirim, terlihat di <b>AI dan Survei</b>.',
        'Tahap dikenali dari namanya di templat properti atau dari syaratnya (tahap bersyarat survei, tahap bersyarat booking), jadi aturan tetap jalan bila urutan pipeline sedikit berbeda.',
        'Dari terminal: <code>php artisan stages:catch-up --tenant=&lt;slug&gt;</code> menampilkan pratinjau yang sama; tambahkan <code>--apply</code> untuk menerapkan.',
    ],
    'perhatian' => [
        'Agen tetap bisa memindah lead secara manual kapan saja, dan lead hanya dipindah maju. Lead yang sudah Closing tidak pernah dipindah otomatis.',
        'Lead yang belum pernah kita hubungi, atau yang chatnya belum dibalas, tidak pernah digugurkan.',
    ],
];
