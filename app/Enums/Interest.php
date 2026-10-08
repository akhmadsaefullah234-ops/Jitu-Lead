<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Interest: string implements HasColor, HasLabel
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Hot => 'danger',
            self::Warm => 'warning',
            self::Cold => 'info',
        };
    }
}
