<?php

namespace App\Shipping\Shipments;

/** Phone numbers the way couriers want them on a label: 08xx. */
final class LocalPhone
{
    public static function number(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;
    }
}
