<?php

use App\Filament\Resources\AiKnowledge\AiKnowledgeResource;
use App\Filament\Resources\AiSuggestions\AiSuggestionResource;

return [
    'title' => 'Pengetahuan AI dan Usulan Q&A',
    'group' => 'percakapan',
    'order' => 40,
    'admin' => true,
    'menu' => 'AI Asisten > Pengetahuan AI, dan AI Asisten > Usulan Q&A',
    'intro' => '<p>AI hanya menjawab dari pengetahuan yang Anda tulis. Makin jelas isinya, makin sedikit chat yang diserahkan ke agen.</p>',
    'pages' => [AiKnowledgeResource::class, AiSuggestionResource::class],
    'steps' => [
        [
            'text' => 'Buka <b>AI Asisten > Pengetahuan AI</b>. Daftar ini berisi pengetahuan umum yang berlaku untuk semua {item}. Kolom <b>Dipakai</b> menyalakan atau mematikan catatan tanpa menghapusnya.',
            'alt' => 'Halaman Pengetahuan umum dengan daftar catatan dan kolom Dipakai',
            'shot' => ['page' => 'ai-knowledge', 'highlight' => ['.fi-ta-ctn']],
        ],
        [
            'text' => 'Klik <b>Tulis catatan</b>. Isi Judul, Pertanyaan klien (opsional), dan Isi / jawaban. Tulis pendek dan faktual: bahan, ukuran, warna, harga, ongkir, cara bayar, tukar barang, estimasi kirim.',
            'alt' => 'Jendela Tulis catatan dengan kolom Judul, Pertanyaan klien, Isi atau jawaban, dan Dipakai AI',
            'shot' => ['page' => 'ai-knowledge', 'steps' => [['click' => 'button:has-text("Tulis catatan")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Klik <b>Unggah dokumen</b> untuk memasukkan katalog atau daftar tanya jawab; isinya dipecah jadi catatan otomatis. <b>Unduh template</b> memberi contoh berkas TXT dan CSV.',
            'alt' => 'Tombol Unggah dokumen dan Unduh template di halaman Pengetahuan AI',
            'shot' => ['page' => 'ai-knowledge', 'highlight' => ['.fi-header button:has-text("Unggah dokumen")', '.fi-header button:has-text("Unduh template")']],
        ],
        [
            'text' => 'Catatan khusus satu {item} ditulis di halaman {item} itu, tab <b>Pengetahuan AI</b>. Nyalakan filter <b>Tampilkan juga pengetahuan {item}</b> untuk melihatnya di daftar ini.',
        ],
        [
            'text' => '<b>Arahan AI (lanjutan)</b> berisi gaya dan aturan khusus AI, serta daftar kata yang membuat AI menyerahkan chat ke agen.',
            'alt' => 'Jendela Arahan AI lanjutan dengan gaya dan aturan khusus serta kata penyerahan ke agen',
            'shot' => ['page' => 'ai-knowledge', 'steps' => [['click' => 'button:has-text("Arahan AI")'], ['waitFor' => '.fi-modal-window:visible']],
                'highlight' => ['.fi-modal-window:visible .fi-modal-content']],
        ],
        [
            'text' => 'Dari chat yang dijawab agen, AI mengusulkan tanya jawab baru di <b>AI Asisten > Usulan Q&A</b>. Periksa, lalu klik <b>Setujui</b> dan pilih <b>Simpan ke</b> {item} tertentu atau pengetahuan umum, atau klik <b>Tolak</b>.',
            'alt' => 'Halaman Usulan Q&A',
            'shot' => ['page' => 'ai-suggestions', 'highlight' => ['.fi-sidebar-item-btn:has-text("Usulan Q&A")']],
        ],
    ],
    'tips' => ['Tulis aturan alamat di pengetahuan umum: data apa yang wajib ditanyakan dan mana yang tidak perlu, supaya AI tidak menanyakan hal yang sudah ada.'],
    'penting' => ['Jangan simpan data pribadi pembeli (nama, nomor HP, alamat) di pengetahuan. Catatan yang memuatnya diberi tanda <b>Data pribadi</b>; tolak usulan seperti itu dan nonaktifkan catatan lama yang terlanjur memuatnya.'],
];
