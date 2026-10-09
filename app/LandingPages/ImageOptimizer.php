<?php

namespace App\LandingPages;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Shrinks every uploaded picture on the server: longest side at most 1600 px
 * (smaller for logos), turned into WebP when this PHP can write it. Phone
 * photos of 5 MB become a few hundred KB, so pages stay light on mobile data.
 * When GD is missing or the file cannot be decoded, the original is stored.
 */
class ImageOptimizer
{
    public const MAX_EDGE = 1600;

    public const MAX_PIXELS = 40_000_000;

    public static function store(TemporaryUploadedFile $file, string $directory, int $maxEdge = self::MAX_EDGE): string
    {
        $encoded = self::encode((string) $file->getRealPath(), $maxEdge);

        if ($encoded === null) {
            return $file->storePublicly($directory, 'public');
        }

        $path = trim($directory, '/').'/'.Str::uuid().'.'.$encoded['ext'];
        Storage::disk('public')->put($path, $encoded['bytes'], 'public');

        return $path;
    }

    /** @return array{bytes: string, ext: string}|null */
    public static function encode(string $file, int $maxEdge = self::MAX_EDGE): ?array
    {
        if (! function_exists('imagecreatefromstring') || ! is_file($file)) {
            return null;
        }

        $info = @getimagesize($file);

        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($file));

        if ($image === false) {
            return null;
        }

        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($file)['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

            if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
                $image = $rotated;
            }
        }

        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = min(1, $maxEdge / max($w, $h));

        if ($scale < 1) {
            $resized = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);

            if ($resized !== false) {
                $image = $resized;
            }
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();

        if (function_exists('imagewebp')) {
            imagewebp($image, null, 82);
            $ext = 'webp';
        } elseif ($info[2] === IMAGETYPE_PNG) {
            imagepng($image, null, 8);
            $ext = 'png';
        } else {
            imagejpeg($image, null, 85);
            $ext = 'jpg';
        }

        $bytes = (string) ob_get_clean();

        return $bytes === '' ? null : ['bytes' => $bytes, 'ext' => $ext];
    }
}
