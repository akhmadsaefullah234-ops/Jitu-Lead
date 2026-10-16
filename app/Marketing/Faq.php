<?php

namespace App\Marketing;

/** The questions and answers shown on the front page and on /faq: one list, so they cannot drift apart. */
class Faq
{
    /** @return list<array{q: string, a: string}> */
    public static function items(): array
    {
        $num = fn ($n) => number_format($n, 0, ',', '.');
        $rp = fn ($n) => 'Rp '.$num($n);
        $ai = config('plans.addons.ai');
        $graceDays = config('plans.grace_days');
        $host = parse_url(url('/'), PHP_URL_HOST);

        return [
            ['q' => 'Apakah nomor WhatsApp saya aman dipakai?', 'a' => 'Anda bisa memakai nomor yang sudah ada dengan scan QR, atau WhatsApp Business API resmi untuk volume besar. Untuk scan QR, gunakan nomor kantor dan hindari pesan massal ke orang yang tidak mengenal Anda.'],
            ['q' => 'Apakah saya perlu API key AI sendiri?', 'a' => 'Tidak. AI sudah disediakan dan kuotanya mengikuti paket.'.($ai ? ' Jika kurang, tambah '.$num($ai['unit']).' balasan seharga '.$rp($ai['price']).'.' : '')],
            ['q' => 'Apakah AI membalas pelanggan tanpa persetujuan saya?', 'a' => 'Anda yang menentukan. AI bisa hanya membuat draf yang dikirim agen, atau membalas otomatis untuk pertanyaan umum. AI berhenti saat agen mengetik dan menyerahkan chat ke agen untuk kata kunci tertentu.'],
            ['q' => 'Bagaimana lead dibagi ke agen?', 'a' => 'Bergiliran sesuai porsi yang Anda atur, misalnya 60% dan 40%, atau dibagi rata. Lead yang tidak ditanggapi dalam batas waktu bisa dipindah otomatis ke agen berikutnya.'],
            ['q' => 'Bagaimana jika lead melebihi batas paket?', 'a' => 'Lead dari formulir dan WhatsApp tetap diterima. Anda mendapat peringatan untuk menaikkan paket.'],
            ['q' => 'Apa yang terjadi jika langganan berakhir?', 'a' => "Ada masa tenggang {$graceDays} hari. Setelah itu akun menjadi baca saja. Data tidak dihapus dan bisa dipakai lagi setelah perpanjang."],
            ['q' => 'Bagaimana cara membayar?', 'a' => 'Saat ini lewat transfer bank. Nomor rekening tertera di menu Langganan setelah Anda masuk.'],
            ['q' => 'Bisa dipakai di HP?', 'a' => "Bisa. Buka {$host} di browser HP, lalu pasang ke layar utama. Tampilannya seperti aplikasi dan notifikasi lead baru masuk langsung."],
        ];
    }
}
