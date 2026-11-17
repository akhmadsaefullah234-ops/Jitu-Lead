<?php

return [
    'title' => 'Kesiapan AI',
    'group' => 'percakapan',
    'order' => 26,
    'industries' => ['properti'],
    'admin' => true,
    'menu' => 'AI Asisten > Kesehatan AI',
    'intro' => '<p><b>Kesiapan AI</b> memberi skor dari 100 untuk pengetahuan agensi: katalog, harga, luas, ketersediaan, lokasi, cara bayar, jam survei, kontak, legalitas, data yang menunggu tinjauan, data yang bertentangan, dan hasil uji AI. AI boleh membalas otomatis setelah skornya minimal 80 dan semua yang <b>Wajib</b> terpenuhi.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>AI Asisten > Kesehatan AI</b>. Setiap baris Kesiapan AI bertanda sudah atau belum, dan yang belum menyebut apa yang perlu diisi serta di mana.',
            'alt' => 'Daftar Kesiapan AI dengan skor dan tanda Wajib',
            'shot' => ['page' => 'kesehatan-ai', 'highlight' => ['.fi-section >> nth=0'], 'mobile' => true],
        ],
        [
            'text' => 'Klik <b>Uji AI saya</b>. AI menjawab 10 pertanyaan klien contoh dari data Anda dan dari playbook, tanpa mengirim pesan ke siapa pun. Uji lulus bila minimal 80% jawabannya benar. Hasil dan riwayatnya ada di bawah; buka jawaban yang gagal untuk melihat apa yang kurang di pengetahuan.',
            'alt' => 'Tombol Uji AI saya dan riwayat hasil uji',
            'shot' => ['page' => 'kesehatan-ai', 'highlight' => ['.fi-header button:has-text("Uji AI saya")', '.fi-section >> nth=1']],
        ],
        [
            'text' => '<b>Uji otomatis</b> menyala untuk agensi baru: uji lengkap sekitar 10 menit setelah pengetahuan berubah dan setiap malam, dan admin menerima email bila nilainya turun. Setelah Kesiapan AI lulus, tombol <b>Naikkan ke Auto</b> di Mode AI bisa diklik.',
            'alt' => 'Sakelar Uji otomatis di Kesehatan AI',
            'shot' => ['page' => 'kesehatan-ai', 'highlight' => ['.fi-header']],
        ],
    ],
    'tips' => [
        'Agensi yang dibuat sebelum fitur ini bisa menyalakan Uji otomatis sendiri di Kesehatan AI. Uji memakai kuota AI agensi.',
    ],
];
