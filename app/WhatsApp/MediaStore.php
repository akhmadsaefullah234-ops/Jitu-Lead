<?php

namespace App\WhatsApp;

use App\Models\WaMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps a picture, voice note, sticker or video a customer sent on the private disk and records where on the
 * message. The type comes from the file itself, never from what the sender or gateway claims, so only real
 * pictures, audio and video files of the known kinds are ever stored (and served).
 */
class MediaStore
{
    private const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
    ];

    /** Details about the file kept beside where it is stored. */
    private const KEEP = ['seconds', 'ptt', 'animated', 'gif', 'round'];

    /** Stores the file of an image, audio, sticker or video message. @return bool whether it was stored */
    public function store(WaMessage $message, string $bytes): bool
    {
        if ($message->type === 'image') {
            return $this->storeImage($message, $bytes);
        }

        $max = (int) (config("whatsapp.max_media_bytes.{$message->type}") ?? 0);

        if ($bytes === '' || $max === 0 || strlen($bytes) > $max) {
            return $this->fail($message, $max === 0 ? 'unsupported' : 'too_large');
        }

        $type = self::sniff($bytes, $message->type);

        if ($type === null) {
            return $this->fail($message, 'unsupported');
        }

        return $this->put($message, $bytes, $type);
    }

    /**
     * The real type of an audio, sticker or video file from its first bytes, as [mime, extension].
     *
     * @return array{0: string, 1: string}|null
     */
    public static function sniff(string $bytes, string $kind): ?array
    {
        $head = substr($bytes, 0, 16);
        $riff = str_starts_with($head, 'RIFF') ? substr($head, 8, 4) : null;

        if ($kind === 'sticker') {
            return $riff === 'WEBP' ? ['image/webp', 'webp'] : null;
        }

        $brand = substr($head, 4, 4) === 'ftyp' ? substr($head, 8, 4) : null;
        $webm = str_starts_with($head, "\x1A\x45\xDF\xA3");

        if ($kind === 'video') {
            return match (true) {
                $brand !== null && str_starts_with($brand, '3g') => ['video/3gpp', '3gp'],
                $brand === 'qt  ' => ['video/quicktime', 'mov'],
                $brand !== null => ['video/mp4', 'mp4'],
                $webm => ['video/webm', 'webm'],
                default => null,
            };
        }

        if ($kind !== 'audio') {
            return null;
        }

        $b0 = ord($head[0] ?? "\0");
        $b1 = ord($head[1] ?? "\0");

        return match (true) {
            str_starts_with($head, 'OggS') => ['audio/ogg', 'ogg'],
            str_starts_with($head, '#!AMR') => ['audio/amr', 'amr'],
            $riff === 'WAVE' => ['audio/wav', 'wav'],
            $brand !== null && str_starts_with($brand, '3g') => ['audio/3gpp', '3gp'],
            $brand !== null => ['audio/mp4', 'm4a'],
            $webm => ['audio/webm', 'webm'],
            str_starts_with($head, 'ID3') => ['audio/mpeg', 'mp3'],
            $b0 === 0xFF && ($b1 & 0xF6) === 0xF0 => ['audio/aac', 'aac'],
            $b0 === 0xFF && ($b1 & 0xE0) === 0xE0 => ['audio/mpeg', 'mp3'],
            default => null,
        };
    }

    /** @param  array{0: string, 1: string}  $type */
    private function put(WaMessage $message, string $bytes, array $type): bool
    {
        $path = "wa-media/{$message->tenant_id}/".Str::uuid().".{$type[1]}";
        Storage::disk('local')->put($path, $bytes);

        $kept = array_intersect_key((array) $message->media, array_flip(self::KEEP));
        $message->forceFill(['media' => ['mime_type' => $type[0], 'path' => $path, 'bytes' => strlen($bytes)] + $kept])->save();

        return true;
    }

    /** @return bool whether the picture was stored */
    public function storeImage(WaMessage $message, string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) > (int) config('whatsapp.max_image_bytes')) {
            return $this->fail($message, 'too_large');
        }

        $info = @getimagesizefromstring($bytes);
        $type = self::TYPES[$info[2] ?? 0] ?? null;

        if ($type === null) {
            return $this->fail($message, 'unsupported');
        }

        return $this->put($message, $bytes, $type);
    }

    public function fail(WaMessage $message, string $reason): bool
    {
        $message->forceFill(['media' => array_filter([...(array) $message->media, 'error' => $reason, 'data' => null])])->save();

        return false;
    }

    public static function deleteTenantFiles(int $tenantId): void
    {
        Storage::disk('local')->deleteDirectory("wa-media/{$tenantId}");
    }
}
