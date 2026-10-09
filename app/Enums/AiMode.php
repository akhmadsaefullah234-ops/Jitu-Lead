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
            self::Off => 'Mati',
            self::Draft => 'Draft untuk agen',
            self::Auto => 'Balas otomatis',
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
