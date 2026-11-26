<?php

namespace App\Tracking;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The ad tags and browser ids a public form sends next to the buyer's answers (fbc, fbp, ttclid, ttp, utm_*,
 * source_url). They never stop an order: a value that is too long or not valid is dropped whole before
 * validation (never cut, since a cut fbc or ttclid no longer matches the ad click) and the lead is saved without it.
 *
 * The form script builds fbc as "fb.1.<milliseconds>." + fbclid, 19 characters longer than the fbclid; Meta's
 * fbclid has been seen up to 212 characters, hence 500 for fbc and ttclid.
 */
class TrackingInput
{
    /** Longest value kept for each field; the validation rules use the same numbers. */
    public const LIMITS = [
        // Kept in the order the forms always validated them: the browser ids reach the ad platforms in this order.
        'source_url' => 500,
        'utm_source' => 200, 'utm_medium' => 200, 'utm_campaign' => 200, 'utm_content' => 200, 'utm_term' => 200,
        'fbp' => 200, 'fbc' => 500, 'ttclid' => 500, 'ttp' => 200,
    ];

    /** A form's hidden field (App\LeadForms\FormFields rules: 'max:300'). */
    public const HIDDEN_MAX = 300;

    /** @return array<string, list<string>> validation rules for these fields (after clean() they always pass) */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::LIMITS as $field => $max) {
            $rules[$field] = ['nullable', 'string', 'max:'.$max];
        }
        $rules['source_url'] = ['nullable', 'url', 'max:500'];

        return $rules;
    }

    /**
     * Removes from the request every tracking value that is too long or not valid, and logs the names and
     * lengths (never the values) so a new kind of ad link that breaks the limits shows up in the log.
     *
     * @param  array<string, mixed>  $context  where it happened (tenant, form), without personal data
     * @return array<string, int|string> the fields that were dropped, with their length (or type)
     */
    public static function clean(Request $request, array $context = [], bool $log = true): array
    {
        $dropped = [];

        foreach (self::LIMITS as $field => $max) {
            $value = $request->input($field);

            if ($value === null || $value === '' || self::valid($field, $value, $max)) {
                continue;
            }

            $dropped[$field] = is_string($value) ? mb_strlen($value) : gettype($value);
            self::forget($request, $field);
        }

        if ($log) {
            self::logDropped($dropped, $context);
        }

        return $dropped;
    }

    /**
     * @param  array<string, int|string>  $dropped  field => length (or type)
     * @param  array<string, mixed>  $context
     */
    public static function logDropped(array $dropped, array $context = []): void
    {
        if ($dropped !== []) {
            Log::info('Public form: tracking values dropped', $context + ['fields' => array_keys($dropped), 'lengths' => $dropped]);
        }
    }

    /**
     * The form's own hidden fields (UTM, fbclid, page address...) under f[...]: the browser cuts them to 300
     * characters; one that still breaks that (a hand-made request, an array) is emptied rather than failing the order.
     *
     * @param  list<array<string, mixed>>  $fields  the form's fields
     * @param  array<string, mixed>  $input  the f[...] input
     * @return array{0: array<string, mixed>, 1: array<string, int|string>} the input, and the hidden keys emptied with their length (or type)
     */
    public static function cleanHidden(array $fields, array $input): array
    {
        $dropped = [];

        foreach ($fields as $f) {
            $value = $input[$f['key']] ?? null;

            if ($f['type'] === 'hidden' && $value !== null && (! is_string($value) || mb_strlen($value) > self::HIDDEN_MAX)) {
                $input[$f['key']] = null;
                $dropped['f.'.$f['key']] = is_string($value) ? mb_strlen($value) : gettype($value);
            }
        }

        return [$input, $dropped];
    }

    private static function valid(string $field, mixed $value, int $max): bool
    {
        if (! is_string($value) || mb_strlen($value) > $max || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        // The same check as the 'url' rule, so a broken page address never fails the order.
        return $field !== 'source_url' || validator(['u' => $value], ['u' => 'url'])->passes();
    }

    private static function forget(Request $request, string $field): void
    {
        $request->request->remove($field);
        $request->query->remove($field);

        if ($request->isJson()) {
            $request->json()->remove($field);
        }
    }
}
