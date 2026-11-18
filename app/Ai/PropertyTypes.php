<?php

namespace App\Ai;

use App\Ai\Facts\FactFormat;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * Unit types in a property agency's chat (properti only):
 *
 * - named(): the catalogue type a client's message names ("berapa harga tipe 36/72?", "tipe melati", "36-72"),
 *   so its knowledge and data are read even before the lead has a product, and the lead gets it (PRODUK);
 * - summary(): one "RINGKASAN TIPE" built from the catalogue for an agency with a few types (at most MAX), so a
 *   question about price or area that names no type is still answered.
 *
 * Must run inside the tenant.
 */
final class PropertyTypes
{
    /** An agency with more types than this gets no summary: the client is asked which type. */
    public const MAX = 10;

    /** Words that name a kind of property, not one type: "harga rumah?" names nothing. */
    private const GENERIC = ['tipe', 'type', 'rumah', 'unit', 'apartemen', 'apartment', 'ruko', 'tanah', 'kavling', 'kaveling', 'perumahan',
        'hunian', 'properti', 'residence', 'townhouse', 'villa', 'gudang', 'kios', 'harga', 'yang', 'dan', 'atau', 'dengan', 'untuk'];

    /** "36/72", "36 / 72", "36-72", "36x72". */
    private const SIZE = '/(?<![\d.,])(\d{2,3})\s*[\/\\\\\-x×]\s*(\d{2,4})(?![\d.,])/u';

    /** "tipe 36" without the land area. */
    private const SIZE_ONE = '/\b(?:tipe|type|tp)\.?\s*(\d{2,3})(?![\d.,\/\-x×])/u';

    /**
     * The one product the text names, or null when it names none or several equally well.
     *
     * @param  Collection<int, Property>  $products
     */
    public static function named(string $text, Collection $products): ?Property
    {
        $text = self::normalize($text);

        if ($text === '' || $products->isEmpty()) {
            return null;
        }

        $asked = self::sizes($text);
        $scores = [];

        foreach ($products as $product) {
            $score = 0;
            $labels = self::labels($product);

            foreach ($labels as $label) {
                if (self::sizesMatch($asked, self::sizes($label))) {
                    $score = max($score, 300);
                } elseif (($words = self::significant($label)) !== [] && self::allIn($words, $text)) {
                    $score = max($score, 100 + mb_strlen(implode(' ', $words)));
                }
            }

            // "tipe: 36/72 dan 45/90" or "Tipe 36/72: ..." in the details of a listing that holds several types.
            if ($score === 0 && self::sizesMatch($asked, self::sizes(self::normalize(collect((array) $product->details)->map(fn ($v, $k) => $k.' '.$v)->implode(' '))))) {
                $score = 50;
            }

            if ($score > 0) {
                $scores[$product->getKey()] = $score;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $top = array_values($scores);

        if (count($top) > 1 && $top[0] === $top[1]) {
            return null;
        }

        return $products->firstWhere('id', array_key_first($scores));
    }

    /**
     * "RINGKASAN TIPE": one line per catalogue product with its price, areas, rooms and availability, written the
     * way the knowledge writes them ("36 m2", "Rp480.000.000 (Rp480 juta)"). Null when there is one product, none,
     * or more than MAX.
     *
     * @param  Collection<int, Property>  $products
     */
    public static function summary(Collection $products): ?string
    {
        if ($products->count() < 2 || $products->count() > self::MAX) {
            return null;
        }

        $lines = $products->map(fn (Property $p) => '- '.self::line($p))->implode("\n");

        return "RINGKASAN TIPE (dibuat otomatis dari katalog agensi; untuk pertanyaan harga, luas, atau ketersediaan yang belum menyebut tipe):\n{$lines}";
    }

    public static function line(Property $p): string
    {
        $details = (array) $p->details;
        $find = fn (string $pattern) => collect($details)->first(fn ($v, $k) => preg_match($pattern, (string) $k) && filled($v));
        $area = fn ($v) => ($n = self::number($v)) !== null ? $n.' m2' : null;
        $rooms = fn ($v) => ($n = self::number($v)) !== null ? $n : null;

        $name = (string) $p->name;
        $type = trim((string) $p->property_type);
        $label = $type !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($type)) ? "{$name} ({$type})" : $name;
        $availability = $find('/ketersediaan|availability/iu') ?? (PropertyResource::STATUSES[$p->status] ?? null);

        $parts = array_filter([
            // A listing of several types ("tipe: 36/72 dan 45/90") is priced "from".
            $p->price_from !== null ? ($find('/^tipe$|^type$/iu') !== null ? 'harga mulai ' : 'harga ').FactFormat::money((int) $p->price_from) : 'harga belum diisi (jangan menyebut harga)',
            ($v = $find('/^tipe$|^type$/iu')) !== null ? 'tipe '.$v : null,
            ...collect($details)->filter(fn ($v, $k) => preg_match('/^(?:tipe|type)\s+\S/iu', (string) $k) && filled($v))->map(fn ($v, $k) => mb_strtolower((string) $k).': '.$v)->values()->all(),
            ($v = $area($find('/bangunan|\blb\b/iu'))) !== null ? 'luas bangunan '.$v : null,
            ($v = $area($find('/tanah|\blt\b/iu'))) !== null ? 'luas tanah '.$v : null,
            ($v = $rooms($find('/kamar.?tidur|\bkt\b|bedroom/iu'))) !== null ? $v.' kamar tidur' : null,
            ($v = $rooms($find('/kamar.?mandi|\bkm\b|bathroom/iu'))) !== null ? $v.' kamar mandi' : null,
            $availability !== null ? mb_strtolower((string) $availability) : null,
        ]);

        return $label.': '.implode('; ', $parts).'.';
    }

    /** "36 m2", "36m²", 36, "36,5 meter" => "36" / "36,5". */
    private static function number(mixed $value): ?string
    {
        return preg_match('/\d+(?:[.,]\d+)?/', (string) $value, $m) ? $m[0] : null;
    }

    /** @return list<string> what a product is called: its type, its name, and the part of the name after " - " */
    private static function labels(Property $product): array
    {
        $name = (string) $product->name;
        $labels = [(string) $product->property_type, $name];

        if (str_contains($name, ' - ')) {
            $labels[] = trim(substr($name, strrpos($name, ' - ') + 3));
        }

        return array_values(array_unique(array_filter(array_map(fn ($l) => self::normalize($l), $labels))));
    }

    /** @return list<string> the words of a label that tell it apart: not "tipe", "rumah" or a size */
    private static function significant(string $label): array
    {
        $words = preg_split('/\s+/u', trim((string) preg_replace(self::SIZE, ' ', $label))) ?: [];

        return array_values(array_filter($words, fn (string $w) => $w !== '' && ! in_array($w, self::GENERIC, true)
            && (mb_strlen($w) >= 3 || preg_match('/\d/', $w))));
    }

    /** Every word appears in the text, a long word with one typo allowed ("melatti" for "melati"). */
    private static function allIn(array $words, string $text): bool
    {
        $tokens = preg_split('/\s+/u', $text) ?: [];

        foreach ($words as $word) {
            $hit = in_array($word, $tokens, true)
                || (mb_strlen($word) >= 5 && collect($tokens)->contains(fn (string $t) => mb_strlen($t) >= 4 && levenshtein($t, $word) <= 1));

            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{0: string, 1: ?string}> sizes in a text: [36, 72] or [36, null] for "tipe 36" */
    private static function sizes(string $text): array
    {
        $out = [];

        if (preg_match_all(self::SIZE, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $out[] = [$hit[1], $hit[2]];
            }
        }

        if (preg_match_all(self::SIZE_ONE, $text, $m)) {
            foreach ($m[1] as $one) {
                $out[] = [$one, null];
            }
        }

        return $out;
    }

    private static function sizesMatch(array $asked, array $own): bool
    {
        foreach ($asked as [$a, $b]) {
            foreach ($own as [$c, $d]) {
                if ($a === $c && ($b === null || $d === null || $b === $d)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        // Keep the marks of a size ("36/72", "36-72"), drop the rest.
        $text = (string) preg_replace('/[^\p{L}\p{N}\/\\\\\-x×]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
