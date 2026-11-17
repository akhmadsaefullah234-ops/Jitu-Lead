<?php

namespace App\Ai\Facts;

/**
 * One way of writing each kind of fact, so every standard knowledge entry reads the same: "38 m2",
 * "Rp642.850.000 (sekitar Rp642,85 juta)". Also what two values are compared by when looking for a conflict.
 */
final class FactFormat
{
    /** The value as it is stored: digits for money, a plain number for areas and counts, trimmed text otherwise. */
    public static function clean(string $key, mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return match (FactKeys::kind($key)) {
            FactKeys::MONEY => ($n = self::rupiah($text)) !== null ? (string) $n : null,
            FactKeys::AREA, FactKeys::COUNT => ($n = self::number($text)) !== null ? self::plain($n) : null,
            default => mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 1000),
        };
    }

    /** The value as the AI and the owner read it. */
    public static function show(string $key, string $value): string
    {
        return match (FactKeys::kind($key)) {
            FactKeys::MONEY => self::money((int) $value),
            FactKeys::AREA => $value.' m2',
            default => $value,
        };
    }

    /** What two values are compared by: the number for money, areas and counts; lower-case words otherwise. */
    public static function comparable(string $key, string $value): string
    {
        return match (FactKeys::kind($key)) {
            FactKeys::MONEY, FactKeys::AREA, FactKeys::COUNT => $value,
            default => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value))),
        };
    }

    /** "Rp642.850.000 (sekitar Rp642,85 juta)"; a round amount needs no "sekitar": "Rp500.000.000 (Rp500 juta)". */
    public static function money(int $amount): string
    {
        $full = 'Rp'.number_format($amount, 0, ',', '.');

        foreach ([1_000_000_000 => 'miliar', 1_000_000 => 'juta'] as $unit => $word) {
            if ($amount >= $unit) {
                $short = round($amount / $unit, 2);
                // "sekitar" unless the short form is a whole number that is exactly the amount ("Rp500 juta").
                $exact = floor($short) == $short && abs($short * $unit - $amount) < 0.5;

                return $full.' ('.($exact ? '' : 'sekitar ').'Rp'.self::plain($short).' '.$word.')';
            }
        }

        return $full;
    }

    /**
     * "642850000", "Rp 642.850.000", "642,85 juta", "1,2 M" => rupiah; null when there is no amount.
     */
    public static function rupiah(string $text): ?int
    {
        $text = mb_strtolower(trim($text));

        if (! preg_match('/(\d[\d.,]*)\s*(miliar|milyar|m\b|juta|jt|ribu|rb)?/u', $text, $m)) {
            return null;
        }

        $unit = match ($m[2] ?? '') {
            'miliar', 'milyar', 'm' => 1_000_000_000,
            'juta', 'jt' => 1_000_000,
            'ribu', 'rb' => 1_000,
            default => 1,
        };

        if ($unit === 1) {
            return (int) preg_replace('/\D/', '', $m[1]) ?: null;
        }

        // "642,85 juta" and "1.2 M": the last separator is the decimal one when it has one or two digits after it.
        $digits = preg_match('/^(\d+(?:[.,]\d{3})*)(?:[.,](\d{1,2}))?$/', $m[1], $p)
            ? (float) (preg_replace('/\D/', '', $p[1]).'.'.($p[2] ?? '0'))
            : (float) str_replace(',', '.', $m[1]);

        return (int) round($digits * $unit) ?: null;
    }

    public static function number(string $text): ?float
    {
        return preg_match('/\d+(?:[.,]\d+)?/', $text, $m) ? (float) str_replace(',', '.', $m[0]) : null;
    }

    /** 38.0 => "38", 642.85 => "642,85". */
    public static function plain(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : str_replace('.', ',', rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'));
    }
}
