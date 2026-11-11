<?php

use App\Filament\Pages\DataPrivacy;

return [
    'title' => 'Data dan privasi',
    'group' => 'akun',
    'order' => 50,
    'admin' => true,
    'menu' => 'Pengaturan > Data & privasi',
    'intro' => '<p>Mengunduh semua data toko, atau menghapus akun beserta datanya.</p>',
    'pages' => [DataPrivacy::class],
    'steps' => [
        [
            'text' => 'Klik <b>Unduh semua data (ZIP)</b>: satu berkas berisi CSV lead dan aktivitasnya, percakapan WhatsApp, {katalog}, landing page, aturan follow-up, pengetahuan AI, anggota tim, langganan, dan chat support. Kata sandi dan kunci integrasi tidak disertakan.',
            'alt' => 'Bagian Unduh data Anda dengan tombol Unduh semua data (ZIP)',
            'shot' => ['page' => 'data-privacy', 'highlight' => ['button:has-text("Unduh semua data (ZIP)"), a:has-text("Unduh semua data (ZIP)")']],
        ],
        [
            'text' => '<b>Hapus agensi dan semua datanya</b> menghapus akun secara permanen dan seketika. Hanya admin yang bisa.',
            'alt' => 'Bagian Hapus agensi',
            'shot' => ['page' => 'data-privacy', 'highlight' => ['button:has-text("Hapus agensi dan semua datanya")']],
        ],
    ],
    'penting' => ['Berkas unduhan berisi data pribadi pembeli. Simpan dengan aman dan jangan dibagikan sembarangan.'],
];
