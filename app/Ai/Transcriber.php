<?php

namespace App\Ai;

/**
 * Turns a voice note into text, so the AI can answer what the customer said instead of asking them to type.
 * Optional: without a provider in .env (TRANSCRIBE_PROVIDER, TRANSCRIBE_API_KEY) configured() is false and
 * voice notes are left for an agent to listen to.
 */
interface Transcriber
{
    public function configured(): bool;

    /**
     * @param  string  $filename  with the file's real extension (.ogg, .m4a, ...), which the provider reads the format from
     * @return string|null what was said, or null when it could not be transcribed
     */
    public function transcribe(string $bytes, string $filename): ?string;
}
