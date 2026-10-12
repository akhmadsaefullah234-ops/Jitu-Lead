<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WaChannelStatus: string implements HasColor, HasLabel
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';
    case NeedsRelink = 'needs_relink';

    public function getLabel(): string
    {
        return match ($this) {
            self::Connected => 'Terhubung',
            self::Disconnected => 'Belum terhubung',
            self::Error => 'Bermasalah',
            self::NeedsRelink => 'Perlu scan ulang',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Disconnected => 'gray',
            self::Error => 'danger',
            self::NeedsRelink => 'warning',
        };
    }
}
