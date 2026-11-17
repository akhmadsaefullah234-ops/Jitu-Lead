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
            'text' => 'Buka <b>AI Asisten > Mode AI</b>. Pada kolom <b>Pembalas</b>, pilih mode untuk tiap nomor. Pilihan tersimpan langsung, tanpa tombol simpan. Agensi properti baru dimulai dengan <b>AI menyiapkan, agen yang kirim</b>; pilihan <b>AI membalas otomatis</b> terbuka setelah tombol <b>Naikkan ke Auto</b> diklik, yang aktif bila katalog properti dan minimal 3 catatan pengetahuan sudah ada.',
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
        'Agensi properti bisa menyalakan <b>Pemeriksa sebelum kirim otomatis</b>: balasan otomatis yang menyebut harga, persentase, tahun, jangka waktu, atau luas yang tidak ada di pengetahuan atau data, atau memuat frasa terlarang (misalnya "siap huni" atau "pasti disetujui"), ditahan sebagai saran di Inbox untuk diperiksa admin. Alasannya tercatat di aktivitas lead. Angka yang ada di pengetahuan dianggap benar walau satuannya ditulis lain (misalnya "Bangunan 38" untuk "38 m2"), rentang seperti "36-45 m2" harus tertulis sama di pengetahuan, dan frasa terlarang yang memang tertulis di pengetahuan Anda (misalnya promo "gratis biaya KPR") tidak ditahan.',
        'Di mode otomatis, balasan AI pertama ke tiap lead bisa membawa brosur dan daftar harga dari Pustaka media, sekali per lead. Pilih itemnya di <b>Pengetahuan AI > Arahan AI (lanjutan) > Lampiran sambutan</b>; bawaannya tidak ada lampiran.',
        'Bila layanan AI gagal menjawab (misalnya sedang gangguan atau terlalu lama), AI mencoba sekali lagi. Bila tetap gagal, chat diserahkan ke agen dengan catatan "AI gagal menjawab" di aktivitas lead, tampil di Inbox, dan admin menerima email (paling banyak satu per jam).',
        'Di mode otomatis, AI tidak membalas balasan otomatis dari bisnis lain (misalnya "pesan otomatis", "terima kasih telah menghubungi", "ketik 1 untuk"), atau chat yang membalas pesan otomatis kita dalam hitungan detik tiga kali berturut-turut. Chat itu dijeda 24 jam dan diserahkan ke agen, supaya dua bot tidak saling membalas.',
        'Untuk agensi properti, pertanyaan yang sudah pernah dijawab AI dari pengetahuan dijawab lagi dengan isi yang sama, tidak kadang dijawab kadang diserahkan. Begitu pengetahuan diubah, AI mulai dari awal.',
        'Chat yang sedang ditangani agen tidak dibalas AI. AI dijeda saat agen mengetik di Inbox (sampai 1 menit setelah berhenti mengetik) dan setelah agen mengirim pesan dari Inbox atau dari WhatsApp di HP (bawaan 5 menit). Selama dijeda, jawaban AI disimpan sebagai saran di Inbox dan tidak dikirim.',
        'Bawaannya, penyerahan ke agen tidak menjeda AI: pertanyaan berikutnya yang bisa dijawab dari pengetahuan tetap dijawab AI, sedangkan pertanyaan yang diserahkan menunggu agen. Agar chat yang diserahkan tetap dipegang agen, isi <b>AI diam setelah menyerahkan chat</b> (misalnya 60 menit) di <b>Arahan AI (lanjutan)</b>. Lama jeda setelah agen membalas juga diatur di sana.',
    ],
];
