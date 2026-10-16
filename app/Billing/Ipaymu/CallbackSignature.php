<?php

namespace App\Billing\Ipaymu;

/**
 * Verifies the X-Signature of an iPaymu callback (docs.ipaymu.com/en/docs/callback): normalize the payload,
 * sort the keys, serialize to JSON and HMAC-SHA256 it with the VA as key. The docs leave a few serialization
 * details open (slash escaping, key order of mixed case, null values), so every sensible variant is tried.
 */
class CallbackSignature
{
    private const INTS = ['trx_id', 'status_code', 'transaction_status_code', 'paid_off'];

    public static function valid(array $payload, ?string $signature, string $va): bool
    {
        $signature = strtolower(trim((string) $signature));

        if ($signature === '' || $va === '') {
            return false;
        }

        foreach (self::candidates($payload) as $json) {
            if (hash_equals($signature, hash_hmac('sha256', $json, $va))) {
                return true;
            }
        }

        return false;
    }

    /** @return \Generator<string> */
    public static function candidates(array $payload): \Generator
    {
        unset($payload['signature']);

        foreach (['null', 'string', 'empty'] as $nulls) {
            $data = self::normalize($payload, $nulls);

            foreach (['bytes', 'locale'] as $order) {
                $sorted = self::sort($data, $order);

                foreach ([JSON_UNESCAPED_UNICODE, 0] as $unicode) {
                    $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | $unicode);

                    yield $json;
                    yield str_replace('/', '\/', $json);
                }
            }
        }
    }

    private static function normalize(array $payload, string $nulls): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::INTS, true)) {
                $out[$key] = (int) $value;
            } elseif ($key === 'is_escrow') {
                $out[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($key === 'additional_info') {
                $out[$key] = is_array($value) ? $value : (is_string($value) && ($d = json_decode($value, true)) !== null && is_array($d) ? $d : []);
            } elseif ($value === null) {
                $out[$key] = match ($nulls) {
                    'string' => 'null', 'empty' => '', default => null
                };
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $out[$key] = (string) $value;
            } else {
                $out[$key] = $value;
            }
        }

        $out['additional_info'] ??= [];

        return $out;
    }

    private static function sort(array $data, string $order): array
    {
        $keys = array_keys($data);

        if ($order === 'bytes') {
            sort($keys, SORT_STRING);
        } else {
            // Mimics localeCompare: punctuation first, then digits, then letters ignoring case (lower before upper on ties).
            $fold = fn (string $k) => preg_replace_callback('/./', fn ($m) => ctype_alpha($m[0]) ? '~'.strtolower($m[0]) : (ctype_digit($m[0]) ? '}'.$m[0] : '|'.$m[0]), $k);
            usort($keys, fn ($a, $b) => strcmp($fold($a), $fold($b)) ?: strcmp($b, $a));
        }

        $sorted = [];

        foreach ($keys as $k) {
            $sorted[$k] = $data[$k];
        }

        return $sorted;
    }
}
