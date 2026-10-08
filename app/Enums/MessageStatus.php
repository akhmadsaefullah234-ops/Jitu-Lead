<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Delivery receipts can arrive out of order; a later state never gets
     * overwritten by an earlier one.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Failed => 0,
            self::Queued => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
        };
    }
}
