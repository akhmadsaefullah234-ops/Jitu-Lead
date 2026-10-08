<?php

namespace App\Support;

/**
 * Normalizes Indonesian phone numbers to E.164 (+62...), so duplicates and
 * WhatsApp chats match no matter how the number was typed.
 */
class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return '+'.$digits;
    }
}
