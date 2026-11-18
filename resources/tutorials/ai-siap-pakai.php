<?php

return [
    'title' => 'AI siap pakai',
    'group' => 'percakapan',
    'order' => 20,
    'industries' => ['properti'],
    'admin' => true,
    'menu' => 'AI Asisten',
    'intro' => '<p>Agensi properti baru tidak perlu mengatur AI. Pengaturan AI, playbook properti, otomatisasi tahap, event iklan, dan label sudah disiapkan Jitu. Yang Anda isi hanya pengetahuan proyek. Setelah itu AI menjawab klien, mencatat kebutuhan, menjadwalkan survei, menggerakkan tahap dan tugas, dan mengirim event ke Meta sendiri. Hasilnya terlihat di <b>AI dan Survei</b>.</p>',
    'steps' => [
        [
            'text' => 'Isi pengetahuan proyek di <b>AI Asisten > Isi properti</b>: proyek, tipe unit dan harga, cara bayar, serta jam dan lokasi survei. Brosur bisa dibaca dari dokumen. Pengaturan AI tidak perlu disentuh; nilai bawaannya sudah untuk properti (gaya WhatsApp, jeda mengetik, serah ke agen 60 menit, uji otomatis).',
            'alt' => 'Wizard Isi properti untuk mengisi pengetahuan proyek',
            'shot' => ['page' => 'isi-properti', 'highlight' => ['.fi-sc-wizard-header']],
        ],
        [
            'text' => 'Mula-mula AI hanya <b>menyiapkan draf</b> dan agen yang mengirim. Setelah <b>Kesiapan AI</b> lulus (lihat bab Kesiapan AI), buka <b>AI Asisten > Mode AI</b> dan klik <b>Naikkan ke Auto</b>. Semua nomor yang menyiapkan draf langsung membalas sendiri.',
            'alt' => 'Halaman Mode AI dengan tombol Naikkan ke Auto',
            'shot' => ['page' => 'ai-modes', 'highlight' => ['.fi-header button:has-text("Naikkan ke Auto")', 'td select >> nth=0']],
        ],
        [
            'text' => 'Buka <b>AI dan Survei</b> di menu. Empat angka di atas: <b>Percakapan baru</b> pada periode itu, persentase yang <b>Membalas pesan kedua</b> (klien menulis lagi setelah jawaban pertama kita), <b>Survei dijadwalkan AI</b> (otomatis dan dari saran AI yang dikirim agen), dan <b>Rata-rata sampai survei</b> sejak lead masuk. Pilih periodenya di atas.',
            'alt' => 'Dasbor AI dan Survei dengan empat angka utama',
            'shot' => ['page' => 'ai-dan-survei', 'highlight' => ['.as-kpis'], 'mobile' => true],
        ],
        [
            'text' => '<b>Serah-terima ke agen</b> menghitung chat yang diserahkan AI beserta alasannya, misalnya klien siap booking atau minta bicara dengan agen. <b>Per tahap</b> menunjukkan berapa lead masuk tiap tahap, event yang dikirim ke Meta, dan label WhatsApp yang terpasang. Label yang dipakai beberapa tahap dihitung sekali di tahap pertamanya.',
            'alt' => 'Tabel serah-terima dan tabel per tahap dengan event Meta dan label',
            'shot' => ['page' => 'ai-dan-survei', 'highlight' => ['.as-grid > * >> nth=0', '.as-grid > * >> nth=1']],
        ],
    ],
    'tips' => [
        'Bila klien menyebut hari survei ("Sabtu besok bisa?"), AI mengulang hari itu dan menawarkan dua jam konkret dulu, baru menanyakan tipe. Bila klien menanyakan tanpa DP padahal agensi memakai DP, AI menjawab tegas bahwa pilihan tanpa DP tidak tersedia lalu menyebut DP dan tanda jadi yang berlaku.',
        'Halaman AI dan Survei untuk admin dan manajer. Agen melihat lead dan tugasnya sendiri seperti biasa.',
        'Ingin mengubah gaya bahasa atau jam malam? Buka Pengetahuan AI > Pengaturan AI. Perubahan Anda hanya berlaku untuk agensi Anda.',
    ],
];
