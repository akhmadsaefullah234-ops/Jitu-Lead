<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PropertyKind: string implements HasLabel
{
    case Primary = 'primary';
    case Secondary = 'secondary';

    public function getLabel(): string
    {
        return match ($this) {
            self::Primary => 'Primary (proyek developer)',
            self::Secondary => 'Secondary (listing)',
        };
    }
}
