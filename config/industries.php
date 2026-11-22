<?php

use App\Ai\AiSettings;
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
                // [name, stage, days, body]; created switched off until the agency checks the wording.
                // Lead baru every 2 days, Dihubungi and Terkualifikasi daily, 7 each; a reply from the client stops the rest.
                ['Lead baru H+2', 'Lead baru', 2, 'Halo Kak {nama}, saya {agen} dari {agensi} 😊 Kemarin Kakak sempat menanyakan {properti}. Boleh tahu, rumahnya untuk ditinggali sendiri atau untuk investasi?'],
                ['Lead baru H+4', 'Lead baru', 4, 'Kak {nama}, kalau masih mencari hunian, saya siap bantu carikan yang pas. Area mana yang paling Kakak incar?'],
                ['Lead baru H+6', 'Lead baru', 6, 'Halo Kak {nama} 🙏 Biar saya tidak salah kasih info, kira-kira budget yang Kakak siapkan di kisaran berapa?'],
                ['Lead baru H+8', 'Lead baru', 8, 'Kak {nama}, banyak klien kami lebih yakin setelah lihat unitnya langsung. Kalau Kakak berkenan, akhir pekan ini bisa saya bantu atur kunjungan ke {properti}.'],
                ['Lead baru H+10', 'Lead baru', 10, 'Halo Kak {nama}, kalau belum sempat datang, saya bisa kirim video unit atau video call dari lokasi. Mau dicoba?'],
                ['Lead baru H+12', 'Lead baru', 12, 'Kak {nama}, apakah masih ada pertanyaan soal {properti}? Cara bayar, lokasi, atau lainnya, tinggal tanya saja ya.'],
                ['Lead baru H+14', 'Lead baru', 14, 'Halo Kak {nama}, ini pesan terakhir dari saya supaya tidak mengganggu 🙏 Kapan pun Kakak siap, balas saja chat ini, saya bantu lagi.'],
                ['Dihubungi H+1', 'Dihubungi', 1, 'Kak {nama}, terima kasih sudah membalas 😊 Supaya saya bisa kasih pilihan yang pas, rumahnya nanti untuk siapa saja?'],
                ['Dihubungi H+2', 'Dihubungi', 2, 'Halo Kak {nama}, kalau boleh tahu rencananya mau bayar tunai, cicilan developer, atau KPR?'],
                ['Dihubungi H+3', 'Dihubungi', 3, 'Kak {nama}, area kerja atau sekolah anak di mana? Biar saya cocokkan dengan lokasi {properti}.'],
                ['Dihubungi H+4', 'Dihubungi', 4, 'Halo Kak {nama} 🙏 Ada tipe atau ukuran rumah yang Kakak bayangkan? Nanti saya bandingkan pilihannya.'],
                ['Dihubungi H+5', 'Dihubungi', 5, 'Kak {nama}, kalau mau lihat langsung, saya bisa bantu jadwalkan survei. Lebih enak Sabtu atau Minggu?'],
                ['Dihubungi H+6', 'Dihubungi', 6, 'Halo Kak {nama}, apakah ada yang masih membuat ragu? Ceritakan saja, saya bantu jelaskan.'],
                ['Dihubungi H+7', 'Dihubungi', 7, 'Kak {nama}, saya tidak akan mengganggu lagi 🙏 Kalau nanti siap melanjutkan, balas chat ini kapan saja ya.'],
                ['Terkualifikasi H+1', 'Terkualifikasi', 1, 'Kak {nama}, dari obrolan kita {properti} sepertinya cocok. Mau saya jadwalkan survei Sabtu pagi atau Minggu siang?'],
                ['Terkualifikasi H+2', 'Terkualifikasi', 2, 'Halo Kak {nama} 😊 Melihat unit langsung biasanya paling membantu memutuskan. Hari apa yang paling longgar minggu ini?'],
                ['Terkualifikasi H+3', 'Terkualifikasi', 3, 'Kak {nama}, kalau belum sempat datang, saya bisa video call dari unit contoh. Mau dicoba sore ini atau besok?'],
                ['Terkualifikasi H+4', 'Terkualifikasi', 4, 'Halo Kak {nama}, apakah ada pertanyaan soal cara bayar atau berkas yang perlu disiapkan? Saya bantu jelaskan.'],
                ['Terkualifikasi H+5', 'Terkualifikasi', 5, 'Kak {nama}, jadwal survei akhir pekan ini masih bisa saya atur. Mau saya catatkan?'],
                ['Terkualifikasi H+6', 'Terkualifikasi', 6, 'Halo Kak {nama} 🙏 Kalau ada pertimbangan lain, misalnya lokasi atau budget, cerita saja, saya carikan alternatifnya.'],
                ['Terkualifikasi H+7', 'Terkualifikasi', 7, 'Kak {nama}, ini pesan terakhir dari saya untuk sementara. Kapan pun mau survei atau tanya lagi, balas chat ini ya 😊'],
            ],
            // The property texts live in code too: an early migration backfills them.
            'ai_instructions' => Defaults::INSTRUCTIONS,
            // New agencies (and "Kembalikan ke bawaan"); an agency's own list is never changed by this.
            'handoff_keywords' => [...HandoffRules::DEFAULTS, ...AiSettings::PROPERTY_HANDOFF],

            // Lead fields this business uses, in form order, with their labels
            'lead_fields' => [
                'need' => 'Kebutuhan',
                'purpose' => 'Tujuan',
                'domicile' => 'Domisili',
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
                ['Tanya produk', StageType::Open, null, 'Balas pertanyaan, kirim foto dan harga', 1, 'inquiry'],
                ['Order masuk', StageType::Open, null, 'Konfirmasi pesanan, ongkir, dan total bayar', 4, 'order'],
                ['Menunggu pembayaran', StageType::Open, null, 'Ingatkan pembayaran', 24, 'payment'],
                ['Dikemas & dikirim', StageType::Open, null, 'Kirim nomor resi', 48, 'shipping'],
                ['Selesai', StageType::Won, StageRequirement::Booking, 'Minta ulasan dan tawarkan order ulang', 168, 'done'],
                ['Batal', StageType::Lost, StageRequirement::LostReason, null, null, 'cancelled'],
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
                'shipping_cost' => 'Ongkir',
                'payment_type' => 'Cara bayar',
                'tracking_number' => 'Nomor resi',
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
        'digital' => [
            'label' => 'Produk digital',
            'icon' => 'heroicon-o-device-phone-mobile',
            'description' => 'Aplikasi, kursus, e-book, template, atau akses digital. Pembayaran lalu akses dikirim otomatis, tanpa alamat atau ongkir.',

            'stages' => [
                // [name, type, requirement, default action, hours until the action is due, template key (App\Automation\DigitalStages)]
                ['Lead baru', StageType::Open, null, 'Kirim penawaran dan tautan bayar', 1, 'new'],
                ['Menunggu pembayaran', StageType::Open, null, 'Ingatkan pembayaran', 6, 'payment'],
                ['Lunas & akses terkirim', StageType::Won, StageRequirement::Booking, 'Minta ulasan dan tawarkan produk lain', 168, 'paid'],
                ['Tidak jadi bayar', StageType::Lost, StageRequirement::LostReason, null, null, 'unpaid'],
                ['Refund', StageType::Lost, StageRequirement::LostReason, null, null, 'refund'],
            ],
            'lost_reasons' => [
                'Harga mahal', 'Ragu fungsi atau manfaat', 'Tidak ada respons', 'Pembayaran gagal', 'Tidak cocok perangkat', 'Minta refund',
            ],
            'lead_sources' => [
                ['Iklan Meta', 'ads'], ['Formulir web', 'form'], ['Instagram', 'social'], ['TikTok', 'social'],
                ['WhatsApp', 'whatsapp'], ['Email', 'manual'], ['Referral', 'manual'],
            ],
            'follow_ups' => [
                // Follow-ups count whole days (follow_up_rules.delay_days), so the payment reminder is the next day.
                ['Sapaan H+1', 'Lead baru', 1, 'Halo {nama}, saya {agen} dari {agensi} 😊 Kemarin Anda menanyakan {produk}. Ada yang ingin ditanyakan soal isi atau cara aksesnya?'],
                ['Pengingat bayar H+1', 'Menunggu pembayaran', 1, 'Halo {nama}, pesanan {produk} Anda masih menunggu pembayaran. Begitu pembayaran kami terima, akses langsung kami kirim ya.'],
                ['Onboarding H+1', 'Lunas & akses terkirim', 1, 'Halo {nama}, terima kasih sudah membeli {produk} 🙏 Apakah aksesnya sudah bisa dibuka? Kalau ada kendala, balas chat ini ya.'],
            ],
            'ai_instructions' => <<<'TXT'
Sapa dengan "Kak" dan gunakan nama pembeli bila diketahui.
Jawab ramah, singkat, dan langsung ke inti. Satu pertanyaan pembeli dijawab satu hal dulu.
Jelaskan isi, manfaat, dan cara pakai produk hanya dari pengetahuan. Setelah menjawab, ajukan satu pertanyaan ringan, misalnya produk mana yang diminati atau perangkat yang dipakai.
Produk ini digital: jangan pernah menanyakan alamat, kurir, atau ongkir.
Bila pembeli sudah siap membeli, minta nama dan email untuk pengiriman akses.
Jangan menyebut harga, diskon, atau masa promo yang tidak tertulis di pengetahuan.
Jangan pernah menyatakan pembayaran diterima atau akses sudah aktif; itu hanya dari sistem atau admin.
Jangan meminta data pribadi sensitif seperti nomor KTP, PIN, kata sandi, atau kode OTP lewat chat.
TXT,
            'handoff_keywords' => [
                'bicara dengan manusia', 'bicara dengan orang', 'bicara dengan admin', 'bicara langsung',
                'telepon saya', 'hubungi saya', 'refund', 'pengembalian dana', 'komplain', 'keluhan', 'kecewa',
                'gagal akses', 'tidak bisa akses', 'tidak bisa login', 'tidak bisa unduh', 'tidak bisa download', 'kode error',
                'nego', 'reseller', 'penipuan',
            ],

            'lead_fields' => [
                'property_id' => 'Produk diminati',
                'quantity' => 'Jumlah',
                'device' => 'Perangkat',
                'payment_type' => 'Cara bayar',
                'paid_at' => 'Lunas pada',
                'access_link' => 'Tautan akses',
            ],

            'terms' => [
                'catalog' => 'Produk digital',
                'catalog_item' => 'produk',
                'catalog_item_name' => 'Nama produk',
                'catalog_icon' => 'heroicon-o-device-phone-mobile',
                'lead_section' => 'Detail pembelian',
                'item_missing' => 'Produk belum dipilih',
                'item_fallback' => 'produk yang Anda tanyakan',
                'visit' => 'Pembelian',
                'visits_upcoming' => 'Menunggu pembayaran',
                'won_stat' => 'Pembelian lunas (30 hari)',
                'deal_item' => 'Pembelian',
                'deal_item_hint' => 'Contoh: Kelas Excel Dasar x1',
                'deal_requirement' => 'Produk dan nilai transaksi',
                'business' => 'bisnis digital',
                'source_portal' => 'Marketplace',
                'knowledge_examples' => 'daftar produk dan harga, fitur dan isi, cara pakai dan akses, perangkat yang didukung, kebijakan refund, promo yang berlaku, dan jam layanan',
                'knowledge_title_hint' => 'Contoh: Harga Kelas Excel, Cara akses, Kebijakan refund',
                'knowledge_body_hint' => 'Tulis apa adanya: harga, isi atau fitur, cara akses, perangkat, masa berlaku, kebijakan refund, jam layanan. AI tidak akan menambah yang tidak tertulis.',
            ],
        ],
    ],
];
