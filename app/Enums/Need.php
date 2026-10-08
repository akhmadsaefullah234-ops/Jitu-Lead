<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Need: string implements HasLabel
{
    case Buy = 'buy';
    case Rent = 'rent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Buy => 'Beli',
            self::Rent => 'Sewa',
        };
    }
}
