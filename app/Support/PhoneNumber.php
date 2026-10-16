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

    /**
     * Digits for a wa.me or tel: link (62812...), or null when the text is not an Indonesian mobile number.
     * "0821-1334-5882", "+62 821 1334 5882" and "62 0821..." all give 6282113345882.
     */
    public static function whatsapp(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || ! preg_match('/^\+?[\d\s().-]+$/', $value)) {
            return null;
        }

        $digits = ltrim((string) self::normalize($value), '+');

        if (str_starts_with($digits, '620')) {
            $digits = '62'.substr($digits, 3);
        }

        return preg_match('/^628\d{8,11}$/', $digits) ? $digits : null;
    }
}
