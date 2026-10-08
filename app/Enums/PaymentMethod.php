<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Kpr = 'kpr';
    case Cash = 'cash';
    case Installment = 'installment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kpr => 'KPR',
            self::Cash => 'Tunai keras',
            self::Installment => 'Tunai bertahap',
        };
    }
}
