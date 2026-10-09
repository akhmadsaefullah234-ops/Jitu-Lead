<?php

namespace App\LandingPages;

use Illuminate\Support\Facades\Storage;

/**
 * Everything on a public page that points outside the page itself: embeds are
 * limited to YouTube and Google Maps, links to http(s), images to this
 * agency's own upload folder.
 */
class Embeds
{
    public static function map(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https://www\.google\.com/maps/embed\?[^\s"\'<>]+$#', $url) ? $url : null;
    }

    /** A normal Google Maps link, for the "buka rute" button. */
    public static function mapLink(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https://(www\.google\.com/maps|maps\.google\.com|maps\.app\.goo\.gl|goo\.gl/maps)[^\s"\'<>]*$#', $url) ? $url : null;
    }

    public static function youtubeId(?string $url): ?string
    {
        $url = trim((string) $url);

        if (preg_match('#^https://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^\s]*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&\#/][^\s]*)?$#', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function link(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https?://[^\s<>"\']+$#i', $url) ? $url : null;
    }

    /** Public URL of an uploaded image, only if it sits in the given agency's folder. */
    public static function image(mixed $value, int|string|null $tenantId): ?string
    {
        $path = self::path($value);

        if ($path === null || ! preg_match('#^landing/'.preg_quote((string) $tenantId, '#').'/[A-Za-z0-9._-]+$#', $path)) {
            return null;
        }

        $url = Storage::disk('public')->url($path);

        return str_starts_with($url, '/') ? url($url) : $url;
    }

    /** @return list<string> */
    public static function images(mixed $value, int|string|null $tenantId): array
    {
        return collect(is_array($value) ? $value : [])->map(fn ($v) => self::image($v, $tenantId))->filter()->values()->all();
    }

    /** A single-file upload is a string, but the form holds it as a one-item list until saved. */
    private static function path(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
