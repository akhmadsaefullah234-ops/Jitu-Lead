<?php

/*
 * The trial email series (php artisan trial-emails:send, daily 09:00 WIB). Each email can be switched off and
 * its text changed in /admin (Email percobaan) without a deploy; the texts here are the defaults.
 *
 * "from": signup = days after the agency was created, end = days relative to the day the trial ends.
 * Placeholders: {nama} {agensi} {hari_tersisa} {tanggal_berakhir} {jumlah_lead} {jumlah_pesan} {paket_percobaan}.
 * "link": where the button goes (whatsapp, knowledge, dashboard, subscription).
 *
 * The ending reminders (end -3 and end -1) replace the old single "segera berakhir" email for trials, so an
 * agency on trial never gets both. Paid subscriptions still get that email before their period ends.
 */
return [

    'enabled' => (bool) env('TRIAL_EMAILS', true),

    // All trial emails together stop for the day at this many messages, to stay inside the mail provider's
    // free quota (Resend free: 100 a day). The rest wait for the next day.
    'daily_limit' => (int) env('TRIAL_EMAILS_DAILY_LIMIT', 80),

    // An email that could not go out on its day (daily limit, unverified admin) is still sent this many days later.
    'window_days' => 3,

    // Sender. Empty = noreply@ the domain of MAIL_FROM_ADDRESS.
    'from_address' => env('TRIAL_EMAILS_FROM'),
    'from_name' => env('TRIAL_EMAILS_FROM_NAME', 'JITU LEAD'),

    'steps' => [
        'connect_whatsapp' => [
            'label' => 'H+1 daftar: sambungkan WhatsApp',
            'from' => 'signup', 'days' => 1, 'link' => 'whatsapp',
            'subject' => 'Langkah pertama: sambungkan WhatsApp {agensi}',
            'button' => 'Sambungkan WhatsApp',
            'body' => "Halo {nama},\n\nTerima kasih sudah mencoba JITU LEAD. Langkah yang paling terasa manfaatnya adalah menyambungkan nomor WhatsApp agensi, supaya chat calon pembeli masuk ke Inbox dan langsung tercatat sebagai lead.\n\n1. Buka menu **Koneksi WhatsApp**.\n2. Tambah nomor, lalu scan kode QR dari HP Anda (WhatsApp > Perangkat tertaut).\n3. Kirim pesan uji ke nomor itu dari HP lain.\n\nSetelah tersambung, follow-up otomatis dan balasan AI juga bisa dipakai.",
        ],
        'ai_knowledge' => [
            'label' => 'H+3 daftar: isi pengetahuan AI',
            'from' => 'signup', 'days' => 3, 'link' => 'knowledge',
            'subject' => 'Ajari AI tentang properti {agensi}',
            'button' => 'Isi pengetahuan AI',
            'body' => "Halo {nama},\n\nAI di JITU LEAD bisa membantu membalas chat calon pembeli, tetapi jawabannya hanya sebaik informasi yang Anda berikan.\n\nBuka menu **Pengetahuan AI** lalu isi hal-hal yang paling sering ditanyakan: daftar proyek dan tipe unit, harga dan cara bayar, lokasi, promo yang berlaku, dan jam kantor. Anda juga bisa mengunggah brosur PDF.\n\nSemakin lengkap isinya, semakin jarang AI menyerahkan chat ke agen.",
        ],
        'usage_summary' => [
            'label' => 'H+7 daftar: ringkasan pemakaian',
            'from' => 'signup', 'days' => 7, 'link' => 'dashboard',
            'subject' => 'Seminggu bersama JITU LEAD: {jumlah_lead} lead, {jumlah_pesan} pesan',
            'button' => 'Buka dasbor',
            'body' => "Halo {nama},\n\nIni ringkasan minggu pertama {agensi} di JITU LEAD:\n\n- Lead tercatat: **{jumlah_lead}**\n- Pesan WhatsApp: **{jumlah_pesan}**\n\nMasa percobaan masih {hari_tersisa} hari lagi, sampai {tanggal_berakhir}. Pakai sisa waktunya untuk mencoba pipeline, follow-up otomatis, dan halaman landing.",
        ],
        'ending_3' => [
            'label' => 'H-3 berakhir: pengingat dan pilih paket',
            'from' => 'end', 'days' => -3, 'link' => 'subscription',
            'subject' => 'Masa percobaan {agensi} berakhir {hari_tersisa} hari lagi',
            'button' => 'Pilih paket dan bayar',
            'body' => "Halo {nama},\n\nMasa percobaan **{agensi}** di JITU LEAD berakhir pada **{tanggal_berakhir}** ({hari_tersisa} hari lagi).\n\nSetelah itu semua menu CRM dikunci sampai agensi memilih paket, dan AI serta follow-up otomatis berhenti. Lead dan chat yang masuk tetap tersimpan. Pilih paket sekarang supaya pekerjaan tim tidak terputus.",
        ],
        'ending_1' => [
            'label' => 'H-1 berakhir: pengingat terakhir',
            'from' => 'end', 'days' => -1, 'link' => 'subscription',
            'subject' => 'Besok masa percobaan {agensi} berakhir',
            'button' => 'Pilih paket dan bayar',
            'body' => "Halo {nama},\n\nMasa percobaan **{agensi}** berakhir besok, **{tanggal_berakhir}**.\n\nPilih paket hari ini agar lead, chat WhatsApp, AI, dan follow-up otomatis tetap berjalan tanpa jeda. Pembayaran bisa langsung dari menu Langganan.",
        ],
        'ended' => [
            'label' => 'H+1 setelah berakhir: akun dikunci',
            'from' => 'end', 'days' => 1, 'link' => 'subscription',
            'subject' => 'Akun {agensi} dikunci, data Anda aman',
            'button' => 'Aktifkan kembali',
            'body' => "Halo {nama},\n\nMasa percobaan **{agensi}** sudah berakhir dan semua menu CRM sekarang dikunci.\n\nSemua data Anda aman: lead, chat, dan pengaturan tetap tersimpan, dan lead serta chat baru tetap masuk. Begitu paket dipilih dan dibayar, semuanya langsung bisa dipakai lagi tanpa perlu mengatur ulang.",
        ],
        'last_offer' => [
            'label' => 'H+7 setelah berakhir: penawaran terakhir',
            'from' => 'end', 'days' => 7, 'link' => 'subscription',
            'subject' => 'Penawaran terakhir untuk {agensi}',
            'button' => 'Lihat paket',
            'body' => "Halo {nama},\n\nSudah seminggu sejak masa percobaan **{agensi}** berakhir. Data Anda masih tersimpan dan menunggu.\n\nKalau ada yang membuat Anda ragu, ceritakan lewat chat bantuan di aplikasi, kami siap membantu menyiapkan semuanya. Pilih paket kapan saja dan akun langsung aktif kembali.",
        ],
    ],

];
