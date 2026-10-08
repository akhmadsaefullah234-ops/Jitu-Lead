<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Extra details a lead must carry before it can enter a stage.
 */
enum StageRequirement: string implements HasLabel
{
    case Survey = 'survey';
    case Booking = 'booking';
    case LostReason = 'lost_reason';

    public function getLabel(): string
    {
        return match ($this) {
            self::Survey => 'Jadwal dan lokasi survei',
            self::Booking => 'Unit dan nilai transaksi',
            self::LostReason => 'Alasan gugur',
        };
    }
}
