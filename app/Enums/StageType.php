<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StageType: string implements HasLabel
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Won => 'Menang',
            self::Lost => 'Gugur',
        };
    }
}
