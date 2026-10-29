<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Messages API client: one text answer, or a short tool-use loop for
 * the shop's shipping tools. No SDK and no streaming; answers are short.
 */
class AnthropicClient
{
    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  Must start and end with a user turn.
     * @return string The answer, or '' when it cannot be trusted: cut off at max_tokens, refused, or empty.
     *                Callers hand an empty answer to a person rather than send it to a client.
     */
    public function reply(string $system, array $messages, int $maxTokens = 1024): string
    {
        $response = $this->send($this->body($system, $messages, $maxTokens));

        if (in_array($response['stop_reason'] ?? null, ['max_tokens', 'refusal'], true)) {
            return '';
        }

        return $this->text($response);
    }

    /**
     * Lets the model call tools until it writes its answer. $run gets a tool's name and input and returns what the
     * tool found; that goes back to the model as the tool result. After $maxRounds tool rounds the answer is ''.
     *
     * @param  list<array{role: 'user'|'assistant', content: string|array}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array}>  $tools
     * @param  callable(string, array): array  $run
     * @return array{text: string, calls: list<array{name: string, input: array, result: array}>}
     */
    public function converse(string $system, array $messages, array $tools, callable $run, int $maxTokens = 1024, int $maxRounds = 4): array
    {
        $calls = [];

        for ($round = 0; $round <= $maxRounds; $round++) {
            $response = $this->send($this->body($system, $messages, $maxTokens) + ['tools' => $tools, 'tool_choice' => ['type' => 'auto']]);
            $stop = $response['stop_reason'] ?? null;

            if ($stop !== 'tool_use') {
                return ['text' => in_array($stop, ['max_tokens', 'refusal'], true) ? '' : $this->text($response), 'calls' => $calls];
            }

            // The assistant turn goes back exactly as it came; every result answers it in one user turn.
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];
            $results = [];

            foreach ($response['content'] as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $result = $run((string) $block['name'], $input);
                $calls[] = ['name' => (string) $block['name'], 'input' => $input, 'result' => $result];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return ['text' => '', 'calls' => $calls];
    }

    private function body(string $system, array $messages, int $maxTokens): array
    {
        $body = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];

        if (filled($thinking = config('services.anthropic.thinking'))) {
            $body['thinking'] = ['type' => $thinking];
        }

        if (filled($effort = config('services.anthropic.effort'))) {
            $body['output_config'] = ['effort' => $effort];
        }

        return $body;
    }

    private function send(array $body): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->acceptJson()->post(config('services.anthropic.url'), $body);

        if (! $response->successful()) {
            throw new RuntimeException('AI error '.$response->status());
        }

        return $response->json() ?? [];
    }

    private function text(array $response): string
    {
        return trim(collect($response['content'] ?? [])->where('type', 'text')->pluck('text')->implode(''));
    }
}
