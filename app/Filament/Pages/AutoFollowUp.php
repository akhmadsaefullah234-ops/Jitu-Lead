<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

class AutoFollowUp extends ComingSoon
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Follow-up otomatis';

    protected static ?string $title = 'Follow-up otomatis';

    protected static ?int $navigationSort = 5;

    protected static array $points = [
        'Atur jadwal pesan otomatis per tahap pipeline, misalnya H+1, H+3, dan H+7 setelah lead masuk.',
        'Edit sendiri isi template pesan dengan variabel nama lead, nama agen, dan properti.',
        'Pesan terkirim lewat jalur WhatsApp yang paling hemat biaya, sesuai aturan jendela gratis.',
        'Berhenti otomatis saat lead membalas atau pindah tahap.',
    ];
}
