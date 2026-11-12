<?php

return [
    'title' => 'Otomatisasi tahap',
    'group' => 'penjualan',
    'order' => 30,
    'industries' => ['retail'],
    'admin' => true,
    'menu' => 'Pengaturan > Otomatisasi tahap',
    'intro' => '<p>Delapan aturan yang memindahkan lead toko antar tahap sendiri, misalnya pindah ke Selesai beberapa hari setelah resi dikirim. Setiap perpindahan otomatis tercatat di aktivitas lead.</p>',
    'steps' => [
        [
            'text' => 'Buka <b>Otomatisasi tahap</b>. Tiap aturan punya sakelar <b>Aktif</b>: 1. Format order lengkap ke Order masuk; 2. Pembeli konfirmasi ke Menunggu pembayaran (transfer) atau Dikemas & dikirim (COD); 3. Gambar saat Menunggu pembayaran ditandai "Bukti transfer masuk"; 4. Nomor resi diisi: kirim WhatsApp resi dan pindah ke Dikemas & dikirim.',
            'alt' => 'Aturan otomatisasi tahap 1 sampai 4 dengan sakelar Aktif',
            'shot' => ['page' => 'otomatisasi-tahap', 'highlight' => ['.fi-section >> nth=0', '.fi-section >> nth=1']],
        ],
        [
            'text' => 'Aturan 5 sampai 7 punya batas hari: 5. Setelah resi dikirim ke Selesai (hari untuk tujuan Pulau Jawa dan luar Jawa); 6. Tidak ada balasan di Tanya produk ke Batal; 7. Belum bayar di Menunggu pembayaran ke Batal. Aturan 8: pembeli Batal yang chat lagi kembali ke Tanya produk. Klik <b>Simpan</b>.',
            'alt' => 'Aturan 5 sampai 8 dengan isian jumlah hari',
            'shot' => ['page' => 'otomatisasi-tahap', 'steps' => [['scroll' => '.fi-section:has-text("5. Setelah resi dikirim")']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("5. Setelah resi dikirim"))', '.fi-section:has(.fi-section-header-heading:has-text("6. Tidak ada balasan"))']],
        ],
    ],
    'tips' => ['Aturan mengenali tahap dari kedudukannya di pipeline toko, bukan dari namanya, jadi tetap jalan walau nama tahap diubah.'],
    'perhatian' => ['Agen tetap bisa memindah lead secara manual kapan saja. Lead yang sudah Selesai tidak dipindah otomatis.'],
];
