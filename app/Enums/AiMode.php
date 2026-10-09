<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AiMode: string implements HasColor, HasLabel
{
    case Off = 'off';
    case Draft = 'draft';
    case Auto = 'auto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Off => 'Saya balas sendiri',
            self::Draft => 'AI menyiapkan, agen yang kirim',
            self::Auto => 'AI membalas otomatis',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Off => 'gray',
            self::Draft => 'warning',
            self::Auto => 'success',
        };
    }
}
