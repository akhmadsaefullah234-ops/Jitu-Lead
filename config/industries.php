<?php

use App\Ai\Defaults;
use App\Ai\HandoffRules;
use App\Enums\StageRequirement;
use App\Enums\StageType;

/*
 * One template per kind of business an agency can pick when it signs up (tenants.industry). It decides the
 * starting pipeline, lost reasons, lead sources, follow-ups and AI instructions (applied by ProvisionTenant and
 * App\Support\IndustryTemplate), which lead fields show and what they are called, and the words the app uses
 * (App\Support\Industry::term()). Placeholders in follow-ups: {nama} {agen} {agensi} {properti} {produk}.
 */
return [
    'default' => 'properti',

    'industries' => [
        'properti' => [
            'label' => 'Properti',
            'icon' => 'heroicon-o-home-modern',
            'description' => 'Agen dan developer: rumah, apartemen, ruko, tanah. Ada jadwal survei, booking unit, dan KPR.',

            'stages' => [
                // [name, type, requirement, default action, hours until the action is due]
                ['Lead baru', StageType::Open, null, 'Hubungi lewat WhatsApp atau telepon', 1],
                ['Dihubungi', StageType::Open, null, 'Gali kebutuhan: tipe, lokasi, budget, cara bayar', 48],
                ['Terkualifikasi', StageType::Open, null, 'Kirim pilihan unit atau listing yang cocok', 48],
                ['Jadwal survei', StageType::Open, StageRequirement::Survey, 'Konfirmasi H-1 sebelum survei', null],
                ['Sudah survei', StageType::Open, null, 'Kirim penawaran dan simulasi KPR', 24],
                ['Negosiasi', StageType::Open, null, 'Follow-up keputusan', 72],
                ['Booking', StageType::Open, StageRequirement::Booking, 'Lengkapi berkas, proses KPR', 168],
                ['Closing', StageType::Won, StageRequirement::Booking, 'Minta referral', 168],
                ['Gugur', StageType::Lost, StageRequirement::LostReason, null, null],
            ],
            'lost_reasons' => [
                'Budget tidak cukup', 'KPR ditolak', 'Membeli di tempat lain', 'Tidak ada respons', 'Hanya cek harga', 'Lokasi tidak cocok',
            ],
            'lead_sources' => [
                ['Iklan Meta', 'ads'], ['Portal properti', 'portal'], ['Formulir web', 'form'],
                ['Referral', 'manual'], ['Pameran', 'manual'], ['Walk-in', 'manual'],
            ],
            'follow_ups' => [
                // [name, stage, days, body]; created switched off until the agency checks the wording
                ['Sapaan H+1', 'Lead baru', 1, 'Halo {nama}, saya {agen} dari {agensi}. Kemarin Anda menanyakan {properti}. Boleh saya bantu dengan informasi unit dan harganya?'],
                ['Tanya kebutuhan H+3', 'Dihubungi', 3, 'Halo {nama}, apakah masih mencari properti? Kalau berkenan, ceritakan budget dan lokasi yang Anda inginkan, nanti saya carikan pilihan terbaik.'],
                ['Tawarkan survei H+7', 'Terkualifikasi', 7, 'Halo {nama}, bagaimana kalau kita jadwalkan survei ke {properti}? Saya bisa atur waktunya sesuai jadwal Anda.'],
            ],
            // The property texts live in code too: an early migration backfills them.
            'ai_instructions' => Defaults::INSTRUCTIONS,
            'handoff_keywords' => HandoffRules::DEFAULTS,

            // Lead fields this business uses, in form order, with their labels
            'lead_fields' => [
                'need' => 'Kebutuhan',
                'property_type' => 'Tipe properti',
                'location' => 'Lokasi incaran',
                'payment_method' => 'Cara bayar',
                'budget_min' => 'Budget minimum',
                'budget_max' => 'Budget maksimum',
                'property_id' => 'Properti diminati',
            ],

            'terms' => [
                'catalog' => 'Properti',               // menu and page title of the catalogue
                'catalog_item' => 'properti',          // one catalogue entry, mid-sentence
                'catalog_item_name' => 'Nama proyek atau listing',
                'catalog_icon' => 'heroicon-o-building-office-2',
                'lead_section' => 'Kebutuhan properti',
                'item_missing' => 'Properti belum dipilih',
                'item_fallback' => 'properti yang Anda tanyakan',
                'visit' => 'Survei',                   // the in-person step of the sale
                'visits_upcoming' => 'Survei 7 hari ke depan',
                'won_stat' => 'Closing (30 hari)',
                'deal_item' => 'Unit',
                'deal_item_hint' => 'Contoh: Blok C-12',
                'deal_requirement' => 'Unit dan nilai transaksi',
                'business' => 'agensi properti',
                'source_portal' => 'Portal properti',
                'knowledge_examples' => 'daftar proyek dan tipe unit, harga dan cara bayar, lokasi, promo yang berlaku, dan jam kantor',
                'knowledge_title_hint' => 'Contoh: Harga Cluster Mawar, Cara KPR, Jam survei',
                'knowledge_body_hint' => 'Tulis apa adanya: harga, DP, cicilan, fasilitas, alamat, syarat KPR, jam survei. AI tidak akan menambah yang tidak tertulis.',
            ],
        ],

        'retail' => [
            'label' => 'Retail / toko online',
            'icon' => 'heroicon-o-shopping-bag',
            'description' => 'Jualan produk lewat WhatsApp, Instagram, TikTok, atau marketplace. Ada order, pembayaran, dan pengiriman.',

            'stages' => [
                ['Tanya produk', StageType::Open, null, 'Balas pertanyaan, kirim foto dan harga', 1],
                ['Order masuk', StageType::Open, null, 'Konfirmasi pesanan, ongkir, dan total bayar', 4],
                ['Menunggu pembayaran', StageType::Open, null, 'Ingatkan pembayaran', 24],
                ['Dikemas & dikirim', StageType::Open, null, 'Kirim nomor resi', 48],
                ['Selesai', StageType::Won, StageRequirement::Booking, 'Minta ulasan dan tawarkan order ulang', 168],
                ['Batal', StageType::Lost, StageRequirement::LostReason, null, null],
            ],
            'lost_reasons' => [
                'Ongkir mahal', 'Stok/ukuran habis', 'Tidak ada respons', 'Beli di marketplace', 'Hanya tanya', 'Batal setelah order',
            ],
            'lead_sources' => [
                ['Iklan Meta', 'ads'], ['Instagram', 'social'], ['TikTok', 'social'], ['Marketplace', 'portal'],
                ['WhatsApp', 'whatsapp'], ['Formulir web', 'form'], ['Referral', 'manual'],
            ],
            'follow_ups' => [
                ['Sapaan H+1', 'Tanya produk', 1, 'Halo {nama}, saya {agen} dari {agensi}. Kemarin Anda menanyakan {produk}. Masih tersedia, mau saya bantu pesankan?'],
                ['Ingatkan pembayaran H+1', 'Menunggu pembayaran', 1, 'Halo {nama}, pesanan {produk} Anda sudah kami siapkan. Begitu pembayaran diterima, pesanan langsung kami kirim ya.'],
                ['Order ulang H+7', 'Order masuk', 7, 'Halo {nama}, terima kasih sudah tertarik dengan {produk}. Ada yang bisa kami bantu supaya pesanannya bisa diproses?'],
            ],
            'ai_instructions' => <<<'TXT'
Sapa dengan "Kak" dan gunakan nama pembeli bila diketahui.
Jawab ramah, singkat, dan langsung ke inti. Satu pertanyaan pembeli dijawab satu hal dulu.
Setelah menjawab, ajukan satu pertanyaan ringan agar percakapan berlanjut, misalnya ukuran atau varian, jumlah, atau kota tujuan pengiriman.
Bila pembeli sudah siap membeli, minta nama, alamat lengkap, dan pilihan kurir untuk menghitung total bayar.
Jangan menyebut harga, diskon, stok, atau ongkir yang tidak tertulis di pengetahuan.
Jangan meminta data pribadi sensitif seperti nomor KTP, PIN, atau kode OTP lewat chat.
TXT,
            'handoff_keywords' => [
                'bicara dengan manusia', 'bicara dengan orang', 'bicara dengan admin', 'bicara langsung',
                'telepon saya', 'hubungi saya', 'komplain', 'keluhan', 'kecewa', 'rusak', 'cacat', 'salah kirim',
                'belum sampai', 'retur', 'tukar', 'refund', 'pengembalian dana', 'nego', 'grosir', 'reseller', 'penipuan',
            ],

            'lead_fields' => [
                'property_id' => 'Produk diminati',
                'variant' => 'Ukuran/varian',
                'quantity' => 'Jumlah',
                'shipping_address' => 'Alamat kirim',
                'courier' => 'Kurir',
            ],

            'terms' => [
                'catalog' => 'Produk',
                'catalog_item' => 'produk',
                'catalog_item_name' => 'Nama produk',
                'catalog_icon' => 'heroicon-o-shopping-bag',
                'lead_section' => 'Detail pesanan',
                'item_missing' => 'Produk belum dipilih',
                'item_fallback' => 'produk yang Anda tanyakan',
                'visit' => 'Pesanan',
                'visits_upcoming' => 'Pesanan diproses',
                'won_stat' => 'Pesanan selesai (30 hari)',
                'deal_item' => 'Pesanan',
                'deal_item_hint' => 'Contoh: Kaos hitam L x2',
                'deal_requirement' => 'Pesanan dan nilai transaksi',
                'business' => 'toko online',
                'source_portal' => 'Marketplace',
                'knowledge_examples' => 'daftar produk dan varian, harga, ukuran dan stok, ongkir dan kurir, promo yang berlaku, dan jam layanan',
                'knowledge_title_hint' => 'Contoh: Harga Kaos Polos, Tabel ukuran, Ongkir ke Jawa',
                'knowledge_body_hint' => 'Tulis apa adanya: harga, varian, ukuran, stok, ongkir, cara bayar, jam layanan. AI tidak akan menambah yang tidak tertulis.',
            ],
        ],
    ],
];
