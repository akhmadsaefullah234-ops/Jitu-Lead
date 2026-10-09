<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Messages API client. The assistant only needs one text answer, so
 * there is no SDK, streaming or tool use here.
 */
class AnthropicClient
{
    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  Must start and end with a user turn.
     */
    public function reply(string $system, array $messages, int $maxTokens = 500): string
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->acceptJson()->post(config('services.anthropic.url'), [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI error '.$response->status());
        }

        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');

        return trim($text);
    }
}
