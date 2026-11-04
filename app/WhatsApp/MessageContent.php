<?php

namespace App\WhatsApp;

use App\Models\WaMessage;

/**
 * What a WhatsApp message other than plain text holds (voice note, sticker, video, location, contact card,
 * reaction), cleaned up from either provider's payload, and how it reads in the Inbox list and to the AI.
 */
final class MessageContent
{
    /** Types whose file is kept on the server and played or shown in the Inbox. */
    public const MEDIA_TYPES = ['image', 'audio', 'sticker', 'video'];

    /** Reasons a file is missing, as the gateway, Cloud API download or MediaStore report them. */
    public const ERRORS = ['too_large', 'download_failed', 'unsupported'];

    /**
     * The media fields a provider may send, typed and trimmed; anything else is dropped.
     *
     * @return array<string, mixed>
     */
    public static function media(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (['mime_type' => 100, 'filename' => 255, 'id' => 255] as $key => $max) {
            if (is_string($raw[$key] ?? null) && trim($raw[$key]) !== '') {
                $out[$key] = mb_substr(trim($raw[$key]), 0, $max);
            }
        }
        foreach (['bytes' => PHP_INT_MAX, 'seconds' => 86400] as $key => $max) {
            if (is_numeric($raw[$key] ?? null) && (int) $raw[$key] >= 0) {
                $out[$key] = min((int) $raw[$key], $max);
            }
        }
        foreach (['ptt', 'animated', 'gif', 'round', 'fetch'] as $key) {
            if (isset($raw[$key]) && is_bool($raw[$key])) {
                $out[$key] = $raw[$key];
            }
        }
        if (in_array($raw['error'] ?? null, self::ERRORS, true)) {
            $out['error'] = $raw['error'];
        }
        if (is_string($raw['data'] ?? null)) {
            $out['data'] = $raw['data'];
        }

        return $out;
    }

    /** @return array{latitude: float, longitude: float, name?: string, address?: string, live?: bool}|null */
    public static function location(mixed $raw): ?array
    {
        if (! is_array($raw) || ! is_numeric($raw['latitude'] ?? null) || ! is_numeric($raw['longitude'] ?? null)) {
            return null;
        }

        $lat = (float) $raw['latitude'];
        $lng = (float) $raw['longitude'];

        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return array_filter([
            'latitude' => $lat,
            'longitude' => $lng,
            'name' => self::text($raw['name'] ?? null, 200),
            'address' => self::text($raw['address'] ?? null, 300),
            'live' => ($raw['live'] ?? false) === true ? true : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Contact cards, as the gateway sends them ([{name, phones: [...]}]) or the Cloud API does
     * ([{name: {formatted_name}, phones: [{phone, wa_id}]}]).
     *
     * @return list<array{name: ?string, phones: list<string>}>|null
     */
    public static function contacts(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $out = [];

        foreach (array_slice(array_values($raw), 0, 10) as $c) {
            if (! is_array($c)) {
                continue;
            }

            $name = is_array($c['name'] ?? null) ? ($c['name']['formatted_name'] ?? null) : ($c['name'] ?? null);
            $phones = [];

            foreach (array_slice((array) ($c['phones'] ?? []), 0, 5) as $p) {
                $value = is_array($p) ? ($p['wa_id'] ?? $p['phone'] ?? null) : $p;
                $value = is_scalar($value) ? preg_replace('/[^\d+]/', '', (string) $value) : '';

                if (strlen(preg_replace('/\D/', '', $value)) >= 5) {
                    $phones[] = mb_substr($value, 0, 20);
                }
            }

            $out[] = ['name' => self::text($name, 120), 'phones' => array_values(array_unique($phones))];
        }

        return $out === [] ? null : $out;
    }

    /** @return array{message_id: string, emoji: string}|null An empty emoji means the reaction was taken back. */
    public static function reaction(mixed $raw): ?array
    {
        if (! is_array($raw) || ! is_string($raw['message_id'] ?? null) || trim($raw['message_id']) === '') {
            return null;
        }

        return ['message_id' => mb_substr(trim($raw['message_id']), 0, 255), 'emoji' => is_string($raw['emoji'] ?? null) ? mb_substr($raw['emoji'], 0, 16) : ''];
    }

    /** "0:23"; null when the length is unknown. */
    public static function duration(?WaMessage $message): ?string
    {
        $seconds = $message?->media['seconds'] ?? null;

        if (! is_int($seconds) || $seconds <= 0) {
            return null;
        }

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function isVoiceNote(WaMessage $message): bool
    {
        // The Cloud API does not always say whether audio was recorded as a voice note; audio counts as one.
        return $message->type === 'audio' && ($message->media['ptt'] ?? true) !== false;
    }

    /** A Google Maps link for a location message. */
    public static function mapsUrl(WaMessage $message): ?string
    {
        $loc = self::location($message->media);

        return $loc === null ? null : 'https://maps.google.com/?q='.$loc['latitude'].','.$loc['longitude'];
    }

    /** What the Inbox shows when a file is not there (yet), e.g. "[Voice note tidak bisa diputar: ukurannya terlalu besar]". */
    public static function unavailable(WaMessage $message): string
    {
        $what = match ($message->type) {
            'audio' => self::isVoiceNote($message) ? 'Voice note' : 'Audio',
            'video' => 'Video',
            'sticker' => 'Stiker',
            default => 'Gambar',
        };
        $verb = in_array($message->type, ['audio', 'video'], true) ? 'diputar' : 'ditampilkan';

        return match ($message->media['error'] ?? null) {
            'too_large' => "[{$what} tidak bisa {$verb}: ukurannya terlalu besar]",
            'download_failed' => "[{$what} tidak bisa {$verb}: gagal diunduh dari WhatsApp]",
            'unsupported' => "[{$what} tidak bisa {$verb}: formatnya tidak didukung]",
            default => "[{$what} sedang dimuat…]",
        };
    }

    /** One line for the conversation list. */
    public static function preview(?WaMessage $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $caption = filled($message->body) ? ': '.mb_strimwidth(trim((string) $message->body), 0, 60, '…') : '';

        return match ($message->type) {
            'text', 'template' => filled($message->body) ? mb_strimwidth(trim((string) $message->body), 0, 80, '…') : null,
            'image' => '📷 Foto'.$caption,
            'video' => '🎥 Video'.$caption,
            'audio' => self::isVoiceNote($message) ? '🎤 Voice note'.(($d = self::duration($message)) ? " {$d}" : '') : '🎵 Audio',
            'sticker' => 'Stiker',
            'document' => '📎 '.($message->media['filename'] ?? 'Dokumen'),
            'location' => '📍 Lokasi',
            'contacts' => '👤 Kontak',
            default => filled($message->body) ? mb_strimwidth(trim((string) $message->body), 0, 80, '…') : 'Pesan',
        };
    }

    /**
     * The message as the AI should read it, or null when it says nothing to the AI. A voice note is its
     * transcript when there is one; without one the AI is only told that it arrived, never what it might say.
     */
    public static function forAi(WaMessage $message): ?string
    {
        $body = trim((string) $message->body);

        return match ($message->type) {
            'location' => self::locationText($message),
            'audio' => filled($message->transcript)
                ? trim((string) $message->transcript)
                : ($message->direction === 'in' ? '[Pelanggan mengirim voice note yang tidak bisa didengar AI]' : '[voice note]'),
            'sticker' => '[stiker]',
            'video' => '[video]'.($body !== '' ? " {$body}" : ''),
            'image' => '[gambar]'.($body !== '' ? " {$body}" : ''),
            'contacts' => '[kartu kontak: '.collect(self::contacts($message->media['contacts'] ?? null) ?? [])->map(fn ($c) => trim(($c['name'] ?? '').' '.implode(', ', $c['phones'])))->implode('; ').']',
            'reaction', 'unsupported' => null,
            default => $body !== '' ? $body : null,
        };
    }

    public static function locationText(WaMessage $message): ?string
    {
        $loc = self::location($message->media);

        if ($loc === null) {
            return filled($message->body) ? 'Pelanggan mengirim lokasi: '.trim((string) $message->body) : null;
        }

        $place = implode(', ', array_filter([$loc['name'] ?? null, $loc['address'] ?? null]));

        return 'Pelanggan mengirim lokasi: '.($place !== '' ? $place.', ' : '').'koordinat '.$loc['latitude'].','.$loc['longitude']
            .' ('.self::mapsUrl($message).')';
    }

    private static function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
