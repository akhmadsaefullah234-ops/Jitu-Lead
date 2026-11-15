<?php

namespace App\Ai;

/**
 * The last check on a property agency's automatic reply: money, percentages, years and areas the answer names must
 * come from what the model was given (PENGETAHUAN, the lead, the chat, tool results), and none of the agency's
 * forbidden phrases ("siap huni", "pasti disetujui") may be promised. The property counterpart of
 * ShopTools::inventsShippingCost. A phrase that is denied right next to it ("belum SHM", "SHM belum tersedia") is
 * not a promise and passes.
 */
final class PropertyGuard
{
    /** "Rp 10 juta", "600 jutaan", "1,5 M", "Rp1.200.000.000", "2 miliar". */
    private const MONEY = '/(?:(rp\.?)\s*)?(?<![\d.,])(\d+(?:[.,]\d+)*)\s*(ribu|rb|k|juta|jutaan|jt|miliar|milyar|triliun|(?-i:M|T))?(?![\p{L}\d])/iu';

    private const PERCENT = '/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:%|persen\b)/iu';

    private const YEAR = '/(?<![\d.,])((?:19|20)\d{2})(?![\d.,])/u';

    private const DURATION = '/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:tahun|thn)\b/iu';

    private const AREA = '/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:m2|m²|meter\s+persegi|meter|are|hektar|ha)(?![\p{L}\d])/iu';

    private const DENIAL = '(?:belum|tidak|tdk|bukan|tanpa|blm|gak|nggak|ga)';

    /**
     * Why the answer may not go out on its own, or [] when it may.
     *
     * @param  string  $given  everything the model was given and may repeat: system prompt, chat, tool results
     * @param  list<string>  $phrases  the agency's forbidden phrases
     * @return list<string>
     */
    public static function problems(string $answer, string $given, array $phrases): array
    {
        $problems = [];
        $known = self::money($given);

        foreach (self::money($answer) as $text => $value) {
            if (! in_array($value, $known, true)) {
                $problems[] = "angka rupiah \"{$text}\" tidak ada di pengetahuan atau data";
            }
        }

        foreach ([self::PERCENT => 'persentase', self::YEAR => 'tahun', self::DURATION => 'jangka waktu', self::AREA => 'luas'] as $pattern => $what) {
            $allowed = self::numbers($pattern, $given);

            foreach (self::numbers($pattern, $answer) as $text => $value) {
                if (! in_array($value, $allowed, true)) {
                    $problems[] = "{$what} \"{$text}\" tidak ada di pengetahuan atau data";
                }
            }
        }

        foreach ($phrases as $phrase) {
            if (self::promises($answer, $phrase)) {
                $problems[] = "frasa terlarang \"{$phrase}\"";
            }
        }

        return array_values(array_unique($problems));
    }

    /** @return array<string, int> each amount as written => its value in rupiah */
    public static function money(string $text): array
    {
        preg_match_all(self::MONEY, $text, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $match) {
            [$whole, $rp, $number, $unit] = $match + [1 => '', 2 => '', 3 => ''];
            $unit = mb_strtolower($unit);

            // A bare number is not money ("2 kamar", "tipe 45"): only with "Rp" or a money unit.
            if ($rp === '' && $unit === '') {
                continue;
            }

            $multiplier = match ($unit) {
                'ribu', 'rb', 'k' => 1_000, 'juta', 'jutaan', 'jt' => 1_000_000, 'miliar', 'milyar', 'm' => 1_000_000_000, 'triliun', 't' => 1_000_000_000_000, default => 1,
            };
            $value = $multiplier === 1
                ? (int) preg_replace('/[.,]/', '', $number)
                : (int) round((float) str_replace(',', '.', (string) preg_replace('/\.(?=\d{3}\b)/', '', $number)) * $multiplier);

            if ($value >= 1_000) {
                $out[trim($whole)] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, string> each number as written => the number alone, "1,5" and "1.5" alike */
    private static function numbers(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $match) {
            $out[trim($match[0])] = str_replace(',', '.', $match[1]);
        }

        return $out;
    }

    /** The phrase is in the answer and not denied right next to it. */
    private static function promises(string $answer, string $phrase): bool
    {
        $quoted = preg_quote(trim($phrase), '/');
        $found = preg_match_all('/(?<![\p{L}\d])'.$quoted.'(?![\p{L}\d])/iu', $answer, $m, PREG_OFFSET_CAPTURE);

        if (! $found) {
            return false;
        }

        foreach ($m[0] as [$text, $offset]) {
            $before = mb_strtolower(substr($answer, max(0, $offset - 30), min(30, $offset)));
            $after = mb_strtolower(substr($answer, $offset + strlen($text), 30));
            $denied = preg_match('/'.self::DENIAL.'(?:\s+(?:ada|ada\s+yang|bisa|pakai|dengan|termasuk|memakai))?\s*$/iu', $before)
                || preg_match('/^[\s\/,]*(?:\p{L}+\s+)?'.self::DENIAL.'\b/iu', $after);

            if (! $denied) {
                return true;
            }
        }

        return false;
    }
}
