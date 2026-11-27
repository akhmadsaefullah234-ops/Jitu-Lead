<?php

return [
    'title' => 'Isi properti, tinjau data, dan Kesehatan AI',
    'group' => 'percakapan',
    'order' => 25,
    'industries' => ['properti'],
    'admin' => true,
    'menu' => 'Pengaturan & Alat > Isi properti',
    'intro' => '<p>AI menjawab harga, luas, cara bayar, dan jadwal survei dari data proyek yang Anda isi sekali di <b>Isi properti</b>. Dari isian itu sistem menulis pengetahuan AI standar dengan penulisan yang sama di setiap jawaban, misalnya "38 m2" dan "Rp642.850.000 (sekitar Rp642,85 juta)", dan setiap tipe unit masuk Katalog properti. <b>Kesehatan AI</b> menunjukkan apa yang masih kurang sebelum AI boleh membalas otomatis.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Pengaturan & Alat > Isi properti</b> dan isi empat langkahnya: <b>Proyek</b> (nama, developer, lokasi, legalitas, serah terima), <b>Tipe unit</b> (harga, luas bangunan, luas tanah, kamar, ketersediaan, bonus), <b>Pembayaran</b> (model pembayaran agensi), lalu <b>Survei</b> (jam, lokasi, dan kontak). Klik <b>Simpan dan perbarui pengetahuan AI</b> di langkah terakhir. Yang dikosongkan tidak dijawab AI, dan chat soal itu diserahkan ke agen.',
            'alt' => 'Wizard Isi properti dengan langkah Proyek, Tipe unit, Pembayaran, dan Survei',
            'shot' => ['page' => 'isi-properti', 'highlight' => ['.fi-sc-wizard-header']],
        ],
        [
            'text' => 'Di langkah <b>Pembayaran</b>, centang hanya cara bayar yang benar-benar tersedia: <b>Cash keras</b>, <b>Cash bertahap</b> (tenor maksimal dan ada atau tidaknya bunga), <b>Cicilan developer</b>, <b>KPR</b> (bank rekanan boleh dikosongkan), atau <b>Lainnya</b>. Lalu pilih <b>Model uang muka</b>: tidak ada DP, DP 0%, DP gratis (promo, dengan masa berlakunya), DP ringan, atau DP standar, dan isi <b>Tanda jadi</b> serta apakah tanda jadi mengurangi harga. AI hanya menanyakan dan menawarkan cara bayar yang dicentang. Bila klien menanyakan cara bayar lain, misalnya KPR yang tidak dicentang, AI menjawab bahwa itu belum tersedia lalu menawarkan yang ada. AI juga tidak menyebut "tanpa DP", "DP 0", atau "gratis" kecuali Anda memilihnya, dan tidak pernah menjanjikan KPR disetujui.',
            'alt' => 'Langkah Pembayaran dengan pilihan cara bayar, uang muka, dan tanda jadi',
            'shot' => ['page' => 'isi-properti', 'steps' => [['click' => '.fi-sc-wizard-footer button:has-text("Selanjutnya")'], ['waitFor' => 'text="Tipe unit" >> visible=true'], ['click' => '.fi-sc-wizard-footer button:has-text("Selanjutnya")'], ['waitFor' => 'text="Cara bayar yang tersedia" >> visible=true']], 'highlight' => ['.fi-sc-wizard-step.fi-active']],
        ],
        [
            'text' => 'Punya brosur atau daftar harga? Klik <b>Baca dari dokumen</b> dan unggah PDF, Word, Excel, TXT, CSV, atau foto. AI menyalin datanya, tetapi belum dipakai sebelum Anda setujui di <b>Tinjau data AI</b>. Setiap data menyimpan nama file asalnya.',
            'alt' => 'Tombol Baca dari dokumen di halaman Isi properti',
            'shot' => ['page' => 'isi-properti', 'highlight' => ['.fi-header button:has-text("Baca dari dokumen")']],
        ],
        [
            'text' => 'Di <b>Tinjau data AI</b>, klik <b>Setujui</b> atau <b>Tolak</b> untuk tiap data dari dokumen. Bila dua sumber menyebut nilai berbeda, misalnya luas tanah 90 dan 95 m2, keduanya ditandai <b>Bertentangan</b>. Klik <b>Pakai nilai ini</b> pada yang benar. Selama belum diputuskan, AI tidak menyebut data itu.',
            'alt' => 'Daftar Tinjau data AI dengan data dari dokumen yang menunggu tinjauan',
            'shot' => ['page' => 'tinjau-data-ai', 'highlight' => ['.fi-ta']],
        ],
        [
            'text' => 'Buka <b>Pengaturan & Alat > Kesehatan AI</b>. <b>Kesiapan AI</b> memberi skor dari 100 dan menyebut apa yang belum ada. Tombol <b>Naikkan ke Auto</b> di Mode AI baru aktif setelah skor minimal 80 dan semua yang <b>Wajib</b> terpenuhi, termasuk <b>Uji AI saya</b>: 10 pertanyaan klien contoh dari data Anda dan dari playbook, tanpa mengirim pesan ke siapa pun.',
            'alt' => 'Halaman Kesehatan AI dengan skor Kesiapan AI dan hasil uji terakhir',
            'shot' => ['page' => 'kesehatan-ai', 'highlight' => ['.fi-section >> nth=0', '.fi-header button:has-text("Uji AI saya")']],
        ],
    ],
    'tips' => [
        'Setiap tipe unit menjadi satu properti di katalog. Bila klien menyebut tipenya di chat, misalnya "berapa harga tipe 36/72?", "tipe 36-72", atau nama tipe, AI langsung membaca data tipe itu dan tipe itu menjadi produk lead, walau lead belum punya produk. Agensi dengan paling banyak 10 tipe juga mendapat <b>Ringkasan tipe</b> otomatis (harga, luas, kamar, ketersediaan dari katalog), sehingga pertanyaan harga atau luas yang belum menyebut tipe tetap terjawab. Entri pengetahuan buatan Anda tidak diubah.',
        'Untuk agensi baru, model pembayaran juga mengatur teks bawaan: tindak lanjut <b>Dihubungi H+2</b> menanyakan cara bayar yang Anda centang, aksi tahap memakai "simulasi pembayaran" dan "proses pembayaran" bila KPR tidak dicentang, dan alasan kalah <b>KPR ditolak</b> hanya ada bila KPR tersedia. Teks yang sudah Anda ubah sendiri tidak disentuh.',
        'Isian lama (cara bayar, DP, dan tenor dalam bentuk teks) tetap terlihat di langkah Pembayaran sebagai <b>catatan</b> selama masih terisi. Dokumen yang dibaca AI tidak bisa mengisi model pembayaran; hanya Anda yang mengisinya di sini.',
        '<b>Uji otomatis</b> (menyala untuk agensi baru) menguji AI lengkap sekitar 10 menit setelah pengetahuan berubah dan tiap malam. Admin menerima email bila nilainya turun. Agensi lama bisa menyalakannya di Kesehatan AI.',
        'Data yang Anda ubah di wizard menggantikan nilai lama; data dari dokumen yang belum ditinjau tetap menunggu dan ditandai bila berbeda.',
    ],
];
