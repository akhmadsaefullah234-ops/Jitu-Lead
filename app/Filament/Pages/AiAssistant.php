<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

class AiAssistant extends ComingSoon
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Asisten';

    protected static ?string $title = 'AI Asisten';

    protected static ?int $navigationSort = 6;

    protected static array $points = [
        'Unggah pengetahuan (brosur, daftar harga, FAQ) agar AI menjawab chat sesuai data agensi Anda.',
        'Pilih mode per nomor WhatsApp: hanya draft untuk disetujui agen, atau balas otomatis.',
        'AI belajar dari percakapan lewat usulan tanya-jawab yang Anda setujui, bukan dilatih ulang.',
        'Serahkan ke agen otomatis saat lead minta bicara orang atau pertanyaan di luar pengetahuan.',
    ];
}
