<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WaChannelType: string implements HasLabel
{
    case Official = 'official';
    case Gateway = 'gateway';

    public function getLabel(): string
    {
        return match ($this) {
            self::Official => 'Resmi (WhatsApp Business API)',
            self::Gateway => 'Gateway (nomor follow-up)',
        };
    }
}
