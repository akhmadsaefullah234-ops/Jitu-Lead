<?php

return [
    'title' => 'Mode AI',
    'group' => 'percakapan',
    'order' => 30,
    'admin' => true,
    'menu' => 'AI Asisten > Mode AI',
    'intro' => '<p>Pilih siapa yang membalas chat untuk tiap nomor WhatsApp. Perubahan langsung berlaku untuk pesan berikutnya.</p><ul>'
        .'<li><b>Saya balas sendiri</b>: AI tidak ikut campur. Bawaan untuk nomor baru.</li>'
        .'<li><b>AI menyiapkan, agen yang kirim</b>: AI menulis saran balasan di Inbox. Agen memilih memakainya atau tidak.</li>'
        .'<li><b>AI membalas otomatis</b>: AI langsung membalas bila jawabannya ada di pengetahuan Anda.</li></ul>',
    'steps' => [
        [
            'text' => 'Buka <b>AI Asisten > Mode AI</b>. Pada kolom <b>Pembalas</b>, pilih mode untuk tiap nomor. Pilihan tersimpan langsung, tanpa tombol simpan.',
            'alt' => 'Halaman Siapa yang membalas chat dengan kolom Pembalas untuk tiap nomor WhatsApp',
            'shot' => ['page' => 'ai-modes', 'highlight' => ['td select >> nth=0']],
        ],
        [
            'text' => 'AI menyerahkan chat ke agen bila pembeli minta bicara dengan orang, mengeluh, menawar, atau menyebut hal sensitif (misalnya komplain, refund, nego, diskon, penipuan); bila jawabannya tidak ada di pengetahuan; bila ongkir ke tujuan itu perlu dikonfirmasi admin; atau bila pesanan tidak bisa dicatat otomatis. Daftar katanya bisa diubah di <b>Pengetahuan AI > Arahan AI (lanjutan)</b>.',
        ],
        [
            'text' => 'Chat yang diserahkan diberi catatan alasan di aktivitas lead, dan kotak <b>AI menyerahkan chat ini ke Anda</b> tampil di Inbox. Balas secepatnya.',
            'alt' => 'Kotak AI menyerahkan chat ini ke Anda di Inbox',
            'shot' => ['page' => 'inbox', 'steps' => [['click' => '.wa-item:has-text("Eka Contoh")'], ['waitFor' => '.ai-draft']],
                'highlight' => ['.ai-draft']],
        ],
    ],
    'perhatian' => [
        'AI membaca sampai 40 pesan terakhir dari tiap chat. Untuk chat yang panjang, AI juga menyimpan ringkasan singkat (nama, domisili, kebutuhan, budget, cara bayar, status survei, dan hal yang sudah dijanjikan), sehingga hal yang disebut klien di awal chat tetap diingat. Ringkasan tidak memuat nomor KTP, nomor rekening, PIN, atau kode OTP. Untuk agensi properti, AI juga membaca data lead: tahap pipeline, kebutuhan, budget, cara bayar, dan jadwal survei.',
        'Untuk agensi properti, AI mencatat kebutuhan yang disebut klien (tujuan, domisili, budget, cara bayar, tipe, lokasi) ke lead tanpa menimpa isian yang sudah ada, kecuali klien mengoreksinya, dan bisa menjadwalkan survei (lihat bab Jadwal survei). Semuanya baru tercatat saat balasan benar-benar terkirim, dan tercatat di aktivitas lead.',
        'Di mode otomatis, AI bisa menunggu jeda acak sebelum membalas (misalnya 5 sampai 25 detik) sambil menampilkan "mengetik..." di WhatsApp klien (nomor scan QR), dan bisa diberi jam malam: AI mengirim satu sambutan singkat per malam, atau diam sampai pagi, lalu menjawab lengkap saat jam malam selesai. Bawaannya tanpa jeda dan tanpa jam malam; atur di <b>Pengetahuan AI > Arahan AI (lanjutan)</b>.',
        'Agensi properti bisa menyalakan <b>Pemeriksa sebelum kirim otomatis</b>: balasan otomatis yang menyebut harga, persentase, tahun, jangka waktu, atau luas yang tidak ada di pengetahuan atau data, atau memuat frasa terlarang (misalnya "siap huni" atau "pasti disetujui"), ditahan sebagai saran di Inbox untuk diperiksa admin. Alasannya tercatat di aktivitas lead.',
        'Penyerahan ke agen tidak menjeda AI. Pertanyaan berikutnya yang bisa dijawab dari pengetahuan tetap dijawab AI, sedangkan pertanyaan yang diserahkan menunggu agen. AI baru dijeda saat agen mengetik (sampai 1 menit setelah berhenti mengetik) dan setelah agen mengirim pesan (5 menit).',
    ],
];
