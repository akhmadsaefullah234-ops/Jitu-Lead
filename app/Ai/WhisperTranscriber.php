<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Speech to text through an OpenAI-style /audio/transcriptions endpoint: OpenAI's Whisper, or Whisper on Groq.
 * The language is fixed (Indonesian by default) so short voice notes are not mistaken for another language.
 */
class WhisperTranscriber implements Transcriber
{
    private const PROVIDERS = [
        'openai' => ['url' => 'https://api.openai.com/v1/audio/transcriptions', 'model' => 'whisper-1'],
        'groq' => ['url' => 'https://api.groq.com/openai/v1/audio/transcriptions', 'model' => 'whisper-large-v3-turbo'],
    ];

    /** Formats these endpoints read; others (AMR, AAC, 3GP) are left for an agent. */
    private const FORMATS = ['ogg', 'mp3', 'm4a', 'wav', 'webm', 'mp4'];

    public function configured(): bool
    {
        return isset(self::PROVIDERS[$this->provider()]) && filled(config('services.transcribe.key'));
    }

    public function transcribe(string $bytes, string $filename): ?string
    {
        if (! $this->configured() || $bytes === '' || ! in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::FORMATS, true)) {
            return null;
        }

        $defaults = self::PROVIDERS[$this->provider()];

        try {
            $response = Http::withToken((string) config('services.transcribe.key'))
                ->acceptJson()
                ->timeout(40)
                ->attach('file', $bytes, $filename)
                ->post(config('services.transcribe.url') ?: $defaults['url'], [
                    'model' => config('services.transcribe.model') ?: $defaults['model'],
                    'language' => config('services.transcribe.language') ?: 'id',
                    'response_format' => 'json',
                    'temperature' => '0',
                ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $text = trim((string) ($response->successful() ? $response->json('text') : ''));

        return $text === '' ? null : mb_substr($text, 0, 4000);
    }

    private function provider(): string
    {
        return strtolower(trim((string) config('services.transcribe.provider')));
    }
}
