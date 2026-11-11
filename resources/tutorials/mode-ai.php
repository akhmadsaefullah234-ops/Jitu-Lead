<?php

use App\Filament\Pages\AiModes;

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
    'pages' => [AiModes::class],
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
        'Penyerahan ke agen tidak menjeda AI. Pertanyaan berikutnya yang bisa dijawab dari pengetahuan tetap dijawab AI, sedangkan pertanyaan yang diserahkan menunggu agen. AI baru dijeda saat agen mengetik (sampai 1 menit setelah berhenti mengetik) dan setelah agen mengirim pesan (5 menit).',
    ],
];
