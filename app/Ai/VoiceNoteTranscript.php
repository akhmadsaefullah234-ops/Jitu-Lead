<?php

namespace App\Ai;

use App\Models\WaMessage;
use Illuminate\Support\Facades\Storage;

/**
 * The text of a customer's voice note, transcribed once and kept on the message (shown under the player in
 * the Inbox). Null when transcription is not set up, the voice note is longer than allowed (2 minutes by
 * default), its file is missing, or the provider could not make it out.
 */
class VoiceNoteTranscript
{
    /** Without a length from WhatsApp, a file bigger than this counts as too long (about 2 minutes of a voice note is 0.3 MB). */
    private const MAX_BYTES_WITHOUT_LENGTH = 2 * 1024 * 1024;

    public function __construct(private Transcriber $transcriber) {}

    public function enabled(): bool
    {
        return $this->transcriber->configured();
    }

    public function for(WaMessage $message): ?string
    {
        if (filled($message->transcript)) {
            return $message->transcript;
        }

        $path = $message->media['path'] ?? null;
        $seconds = $message->media['seconds'] ?? null;

        if ($message->type !== 'audio' || ! $this->enabled() || ! is_string($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $tooLong = is_int($seconds)
            ? $seconds > (int) config('services.transcribe.max_seconds', 120)
            : Storage::disk('local')->size($path) > self::MAX_BYTES_WITHOUT_LENGTH;

        if ($tooLong) {
            return null;
        }

        $text = $this->transcriber->transcribe((string) Storage::disk('local')->get($path), basename($path));

        if ($text !== null) {
            $message->forceFill(['transcript' => $text])->save();
        }

        return $text;
    }
}
