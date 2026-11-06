<?php

namespace App\Leads;

/**
 * When a shipping address reaches the door. With an RT/RW the house number and the landmark are optional: they
 * never hold an order or a resi. Without one (a dusun or kampung address, or an RT/RW written as 0 or 000/000) the
 * courier needs a house number or a landmark ("dekat masjid"), so the address is "belum lengkap" until one is given:
 * resi otomatis skips it, "Buat resi" asks for it, and the chat extraction marks it.
 *
 * Used by the form rule "landmark_if_no_rt" (App\LeadForms\FormFields), AutoShipments, Shipments, ChatShippingDetails
 * and the AI's order check (App\Ai\ShopTools).
 */
class AddressCheck
{
    public const MESSAGE = 'Isi nomor rumah atau patokan karena alamat tanpa RT/RW.';

    /** What the lead list and resi otomatis say. */
    public const PROBLEM = 'alamat tanpa RT/RW belum ada nomor rumah atau patokan';

    /** An RT/RW in a text ("RT 3 RW 5", "RT/RW 003/005", "Rt.01/02"). */
    public const RT_RW_PATTERN = '/\bRT\s*\/\s*RW\.?\s*[:.]?\s*\d{1,3}\s*[\/\-.]\s*\d{1,3}\b|\bRT\.?\s*[:.]?\s*\d{1,3}(?:\s*(?:[\/,\-]\s*|\s+)(?:RW\.?\s*[:.]?\s*)?\d{1,3})?\b/iu';

    /** Whether an RT/RW value really is one: something other than empty, "-", 0, 000 or 000/000. */
    public static function hasRtRw(mixed $rtRw): bool
    {
        return is_scalar($rtRw) && preg_match('/[1-9]/', (string) $rtRw) === 1;
    }

    /** An address split in parts (shipping_address_parts, a form answer) lacks the house number or landmark it needs. */
    public static function needsLandmark(array $parts): bool
    {
        $filled = fn (string $key) => is_scalar($parts[$key] ?? null) && trim((string) $parts[$key]) !== '';

        return ! self::hasRtRw($parts['rt_rw'] ?? null) && ! $filled('house') && ! $filled('landmark');
    }

    /**
     * A lead's address lacks the house number or landmark it needs: by its parts, and (when given) its text, which may
     * hold the RT/RW or the house number the parts do not ("Jl. Mawar No. 5 RT 001/002, …").
     */
    public static function leadNeedsLandmark(?string $address, array $parts): bool
    {
        $address = (string) $address;

        return self::needsLandmark($parts) && ! self::textHasRtRw($address) && ! self::textHasLandmark($address);
    }

    /** @return ?string what the address still needs, as said to the agent */
    public static function problem(array $parts, ?string $address = null): ?string
    {
        return self::leadNeedsLandmark($address, $parts) ? self::MESSAGE : null;
    }

    /** The RT/RW written in an address text, when it is a real one. */
    public static function textHasRtRw(string $address): bool
    {
        preg_match_all(self::RT_RW_PATTERN, $address, $m);

        foreach ($m[0] as $found) {
            if (self::hasRtRw(preg_replace('/^\D+/', '', $found))) {
                return true;
            }
        }

        return false;
    }

    /** An address text names a dusun, dukuh or kampung. */
    public static function textHasDusun(string $address): bool
    {
        return preg_match('/\b(?:dusun|dsn|dukuh|kampung|kp|lingkungan|lingk)\b/iu', $address) === 1;
    }

    /** An address text names a house number, a block, a gang or a landmark ("dekat masjid", "patokan …"). */
    public static function textHasLandmark(string $address): bool
    {
        // RT/RW, postal codes and "Dusun 2" are numbers that are not a house number.
        $rest = preg_replace([self::RT_RW_PATTERN, '/\b\d{5}\b/', '/\b(?:dusun|dsn|dukuh|kampung|kp|rw|rt|lingkungan|lingk)\.?\s*\d{1,3}\b/iu'], ' ', $address);

        return preg_match('/\b(?:no|nomor|nmr)\.?\s*\d+|\bblok\s*\w+|\b(?:dekat|depan|samping|sebelah|belakang|seberang|patokan|gang|gg|ancer)\b|\b\d{1,4}[a-z]?\b(?!\s*\d)/iu', $rest) === 1;
    }
}
