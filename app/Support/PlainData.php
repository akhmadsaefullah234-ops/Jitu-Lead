<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Reads back what a value object wrote with toData(): plain values only (strings, ints, null, arrays). Anything
 * missing or of another type throws, so a caller can treat a bad cache entry as a miss instead of using it.
 */
final class PlainData
{
    public static function string(array $data, string $key): string
    {
        return is_string($value = self::value($data, $key)) ? $value : throw self::invalid($key, 'string');
    }

    public static function nullableString(array $data, string $key): ?string
    {
        return ($value = self::value($data, $key)) === null || is_string($value) ? $value : throw self::invalid($key, 'string or null');
    }

    public static function int(array $data, string $key): int
    {
        return is_int($value = self::value($data, $key)) ? $value : throw self::invalid($key, 'int');
    }

    public static function nullableInt(array $data, string $key): ?int
    {
        return ($value = self::value($data, $key)) === null || is_int($value) ? $value : throw self::invalid($key, 'int or null');
    }

    /** @return list<array<string, mixed>> a list of nested toData() arrays */
    public static function rows(array $data, string $key): array
    {
        $value = self::value($data, $key);

        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn ($row) => ! is_array($row)) !== []) {
            throw self::invalid($key, 'list of arrays');
        }

        return $value;
    }

    private static function value(array $data, string $key): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : throw new InvalidArgumentException("Missing '{$key}'.");
    }

    private static function invalid(string $key, string $type): InvalidArgumentException
    {
        return new InvalidArgumentException("'{$key}' must be {$type}.");
    }
}
