<?php

use App\Filament\Pages\Reports;

return [
    'title' => 'Laporan',
    'group' => 'iklan',
    'order' => 30,
    'menu' => 'Laporan',
    'intro' => '<p>Ringkasan kinerja pada periode yang dipilih: corong pipeline, hasil per sumber lead, dan hasil per agen. Agen melihat angka lead miliknya.</p>',
    'pages' => [Reports::class],
    'steps' => [
        [
            'text' => 'Buka <b>Laporan</b> dan pilih <b>Periode</b> (bawaan 30 hari terakhir). Periode yang dipilih diingat untuk kunjungan berikutnya.',
            'alt' => 'Halaman Laporan dengan pemilih periode dan angka ringkasan',
            'shot' => ['page' => 'reports', 'highlight' => ['.fi-fo-field:has-text("Periode")']],
        ],
        [
            'text' => '<b>Corong pipeline</b> menunjukkan berapa lead di tiap tahap. <b>Per sumber lead</b> dan <b>Per agen</b> menunjukkan jumlah lead, closing, konversi, dan nilai transaksi.',
            'alt' => 'Tabel Corong pipeline, Per sumber lead, dan Per agen',
            'shot' => ['page' => 'reports', 'steps' => [['scroll' => '.fi-section:has(.fi-section-header-heading:text-is("Per sumber lead"))']],
                'highlight' => ['.fi-section:has(.fi-section-header-heading:text-is("Per sumber lead"))', '.fi-section:has(.fi-section-header-heading:text-is("Per agen"))']],
        ],
    ],
    'tips' => ['Periode juga bisa dibuka lewat tautan, misalnya <b>?periode=this_month</b> di akhir alamat halaman.'],
];
