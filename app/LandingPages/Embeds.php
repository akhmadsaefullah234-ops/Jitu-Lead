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

    /**
     * Width and height of an uploaded image of this agency, for the width/height attributes that keep the page from
     * jumping while it loads. Null when it is not one of the agency's images or cannot be read.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function imageSize(mixed $value, int|string|null $tenantId): ?array
    {
        if (self::image($value, $tenantId) === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = self::path($value);
        $size = $disk->exists($path) ? @getimagesize($disk->path($path)) : false;

        return $size && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : null;
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

    /** An uploaded video of this agency (mp4/webm), as a public URL. */
    public static function video(mixed $value, int|string|null $tenantId): ?string
    {
        $path = self::path($value);

        if ($path === null || ! preg_match('#^landing/'.preg_quote((string) $tenantId, '#').'/[A-Za-z0-9._-]+\.(mp4|webm)$#i', $path)) {
            return null;
        }

        $url = Storage::disk('public')->url($path);

        return str_starts_with($url, '/') ? url($url) : $url;
    }

    /** A video file elsewhere on the web: https and ending in .mp4 or .webm. */
    public static function videoUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https://[^\s<>"\']+\.(mp4|webm)(\?[^\s<>"\']*)?$#i', $url) ? $url : null;
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
