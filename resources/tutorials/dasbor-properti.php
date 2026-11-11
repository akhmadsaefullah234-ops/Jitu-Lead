<?php

return [
    'title' => 'Dasbor dan laporan properti',
    'group' => 'mulai',
    'order' => 21,
    'industries' => ['properti'],
    'menu' => 'Dasbor',
    'intro' => '<p>Untuk agensi properti, Dasbor menghitung survei, booking, dan closing. Cara memilih periode dijelaskan di bab Dasbor dan pemilih periode. Agen hanya melihat angka lead miliknya.</p>',
    'steps' => [
        [
            'text' => 'Baris kartu: Lead baru, Lead dari iklan, Lead Hot, Waktu balas pertama, lalu <b>Survei terjadwal</b> (survei yang tanggalnya jatuh di periode ini), <b>Survei terlaksana</b> (lead yang masuk Sudah survei), <b>Booking</b>, dan <b>Akad / closing</b> beserta jumlah nilai transaksinya. Angka kecil di bawahnya adalah periode sebelumnya.',
            'alt' => 'Kartu dasbor properti: lead baru, lead dari iklan, lead Hot, waktu balas pertama, survei terjadwal, survei terlaksana, booking, akad / closing',
            'shot' => ['page' => '', 'steps' => [['select' => ['.jl-period-row select', '30d']], ['wait' => 800]], 'highlight' => ['.fi-wi-stats-overview >> nth=0'], 'mobile' => true],
        ],
        [
            'text' => '<b>Per agen</b> (untuk admin dan team leader, bila tim lebih dari satu orang): lead yang masuk ke tiap agen, Booking, Closing, dan waktu balas pertama pada periode itu.',
            'alt' => 'Tabel Per agen dengan kolom Lead, Booking, Closing, dan Balas pertama',
            'shot' => ['page' => '', 'steps' => [['select' => ['.jl-period-row select', '30d']], ['wait' => 800], ['scroll' => '.fi-section:has(.fi-section-header-heading:has-text("Per agen"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("Per agen"))']],
        ],
        [
            'text' => '<b>Per properti</b>: lead, Hot, Booking, Closing, nilai, dan konversi per proyek atau listing. Klik nama properti untuk membuka daftar Lead dengan filter yang sama.',
            'alt' => 'Tabel Per properti di dasbor',
            'shot' => ['page' => '', 'steps' => [['select' => ['.jl-period-row select', '30d']], ['wait' => 800], ['scroll' => '.fi-section:has(.fi-section-header-heading:has-text("Per properti"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:has-text("Per properti"))']],
        ],
        [
            'text' => 'Event konversi ke Meta untuk properti: <b>Jadwal survei = Schedule</b> dan <b>Closing = Purchase</b>. Keduanya mati sampai admin menyalakannya di <b>Pelacakan iklan</b> (lihat bab Pelacakan iklan Meta dan TikTok).',
        ],
    ],
    'tips' => ['Menu Laporan berisi Corong pipeline, Per sumber lead, dan Per agen (lead, closing, konversi, dan nilai transaksi) untuk periode yang Anda pilih.'],
];
