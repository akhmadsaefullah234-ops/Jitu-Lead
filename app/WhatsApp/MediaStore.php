<?php

namespace App\WhatsApp;

use App\Models\WaMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps a picture a customer sent on the private disk and records where on the message.
 * The type comes from the picture itself, never from what the sender or gateway claims,
 * so only real JPEG, PNG, WebP and GIF files are ever stored (and served).
 */
class MediaStore
{
    private const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
    ];

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

        $path = "wa-media/{$message->tenant_id}/".Str::uuid().".{$type[1]}";
        Storage::disk('local')->put($path, $bytes);

        $message->forceFill(['media' => ['mime_type' => $type[0], 'path' => $path, 'bytes' => strlen($bytes)]])->save();

        return true;
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
