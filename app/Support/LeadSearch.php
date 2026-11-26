<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * One search box for leads: name, email or phone number, the same everywhere
 * (Pipeline, the Lead list, global search, Pengiriman and the Inbox).
 *
 * Phones are stored as +62..., while buyers and CS type 0812..., 62812...,
 * "+62 812-3456-7890" or only the last digits. A term is a phone search only
 * when it has no letters and at least 3 digits; spaces, dashes, brackets and
 * "+" are ignored. A name such as "zzzzqqq" never matches every lead.
 */
class LeadSearch
{
    public const MIN_DIGITS = 3;

    /**
     * LIKE patterns for the phone column, or [] when the term is not a phone search.
     * "0812-34" gives ["%081234%", "%6281234%"]; "+62 812" gives ["%62812%"].
     *
     * @return list<string>
     */
    public static function phonePatterns(?string $term): array
    {
        $term = trim((string) $term);

        if ($term === '' || preg_match('/\pL/u', $term)) {
            return [];
        }

        $digits = (string) preg_replace('/\D+/', '', $term);

        if (strlen($digits) < self::MIN_DIGITS) {
            return [];
        }

        $patterns = ['%'.$digits.'%'];

        if (str_starts_with($digits, '0')) {
            $patterns[] = '%62'.substr($digits, 1).'%';
        }

        return $patterns;
    }

    /**
     * Narrow $query to leads whose name or email contains the term (any case),
     * or whose phone matches it. $columns are the lead columns to compare, so a
     * query on another table can pass qualified names (e.g. "leads.name").
     *
     * @param  array{name?: string|null, email?: string|null, phone?: string|null}  $columns
     */
    public static function apply(Builder $query, ?string $term, array $columns = []): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $columns += ['name' => 'name', 'email' => 'email', 'phone' => 'phone'];
        $like = '%'.self::escapeLike(mb_strtolower($term)).'%';
        $phones = self::phonePatterns($term);

        return $query->where(function (Builder $w) use ($columns, $like, $phones) {
            // Never an empty group: Laravel drops it, and the search would then match every lead.
            $w->whereRaw('1 = 0');

            foreach (['name', 'email'] as $key) {
                if (filled($columns[$key])) {
                    $w->orWhereRaw('lower('.$w->getQuery()->getGrammar()->wrap($columns[$key]).") like ? escape '!'", [$like]);
                }
            }

            if (filled($columns['phone'])) {
                foreach ($phones as $pattern) {
                    $w->orWhere($columns['phone'], 'like', $pattern);
                }
            }
        });
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
