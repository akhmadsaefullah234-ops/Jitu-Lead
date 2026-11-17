<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;

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
        $response = $this->send($this->body($system, self::normalize($messages), $maxTokens));

        if (in_array($response['stop_reason'] ?? null, ['max_tokens', 'refusal'], true)) {
            return '';
        }

        return $this->text($response);
    }

    /**
     * Lets the model call tools until it writes its answer. $run gets a tool's name and input and returns what the
     * tool found; that goes back to the model as the tool result. After $maxRounds tool rounds the answer is ''.
     *
     * The trace says why an answer came back empty (stop_reason, tool rounds, the tools called, the last result, and
     * any text the model wrote next to a tool call), so a handoff "terpotong atau ditolak" can be explained.
     *
     * @param  list<array{role: 'user'|'assistant', content: string|array}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array}>  $tools
     * @param  callable(string, array): array  $run
     * @param  list<string>  $stopTools  tools that end the answer at once (e.g. handing the chat to a person): their
     *                                   call is returned with no result and no text, and the model is not asked again
     * @return array{text: string, calls: list<array{name: string, input: array, result: array}>, trace: array{stop_reason: ?string, rounds: int, tools: list<string>, last_tool: ?string, last_result: ?array, tool_text: string}}
     */
    public function converse(string $system, array $messages, array $tools, callable $run, int $maxTokens = 1024, int $maxRounds = 4, array $stopTools = []): array
    {
        $messages = self::normalize($messages);
        $calls = [];
        // Text the model wrote in a round that also called a tool ("Pesanan kakak: ... Total Rp 240.000"), last first.
        $toolText = '';
        $trace = function (?string $stop, int $rounds) use (&$calls, &$toolText): array {
            $last = $calls === [] ? null : $calls[array_key_last($calls)];

            return ['stop_reason' => $stop, 'rounds' => $rounds, 'tools' => array_column($calls, 'name'),
                'last_tool' => $last['name'] ?? null, 'last_result' => $last['result'] ?? null, 'tool_text' => $toolText];
        };

        for ($round = 0; $round <= $maxRounds; $round++) {
            $response = $this->send($this->body($system, $messages, $maxTokens) + ['tools' => $tools, 'tool_choice' => ['type' => 'auto']]);
            $stop = $response['stop_reason'] ?? null;

            if ($stop !== 'tool_use') {
                return ['text' => in_array($stop, ['max_tokens', 'refusal'], true) ? '' : $this->text($response), 'calls' => $calls, 'trace' => $trace($stop, $round)];
            }

            foreach ($response['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_use' && in_array($block['name'] ?? null, $stopTools, true)) {
                    $calls[] = ['name' => (string) $block['name'], 'input' => is_array($block['input'] ?? null) ? $block['input'] : [], 'result' => []];

                    return ['text' => '', 'calls' => $calls, 'trace' => $trace($stop, $round)];
                }
            }

            $toolText = $this->text($response) !== '' ? $this->text($response) : $toolText;

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

        return ['text' => '', 'calls' => $calls, 'trace' => $trace('max_rounds', $maxRounds + 1)];
    }

    /**
     * Asks the model to fill one tool's input from $content (text and image blocks), and returns that input; null when
     * the model did not fill it. Thinking is left off: a forced tool choice does not take it.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  array{name: string, description: string, input_schema: array}  $tool
     */
    public function structured(string $system, array $content, array $tool, int $maxTokens = 4096): ?array
    {
        $response = $this->send([
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
            'tools' => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
        ]);

        if (($response['stop_reason'] ?? null) === 'max_tokens') {
            return null;
        }

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $tool['name'] && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        return null;
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
            throw new AiException($response->status(), self::reason($response->json()));
        }

        return $response->json() ?? [];
    }

    /**
     * The API's own words for a refused request ("messages: ... must end with a user turn"), short and without
     * anything quoted from the chat: the API names fields and rules, and digits are masked to be safe.
     */
    public static function reason(mixed $json): string
    {
        $type = is_array($json) ? (string) ($json['error']['type'] ?? '') : '';
        $message = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
        $message = (string) preg_replace('/\d{4,}/', '[angka]', preg_replace('/\s+/', ' ', $message));

        return trim($type.($message !== '' ? ': '.mb_strimwidth($message, 0, 200, '…') : ''));
    }

    /**
     * Plain chat turns as the API accepts them: starting with the client, the same role never twice in a row (joined
     * with a line break), and ending with the client, since a chat that ends with the agency (a night greeting, a
     * handed-off marker) is refused with a 400 by models that do not take a prefilled answer. Turns that already
     * carry blocks (tool rounds) are left as they are.
     *
     * @param  list<array{role: string, content: string|array}>  $messages
     * @return list<array{role: string, content: string|array}>
     */
    public static function normalize(array $messages): array
    {
        $out = [];

        foreach ($messages as $m) {
            $last = $out === [] ? null : array_key_last($out);

            if ($last !== null && $out[$last]['role'] === $m['role'] && is_string($out[$last]['content']) && is_string($m['content'])) {
                $out[$last]['content'] = trim($out[$last]['content']."\n".$m['content']);

                continue;
            }

            $out[] = $m;
        }

        while ($out !== [] && $out[0]['role'] !== 'user') {
            array_shift($out);
        }

        while ($out !== [] && $out[array_key_last($out)]['role'] !== 'user' && is_string($out[array_key_last($out)]['content'])) {
            array_pop($out);
        }

        return array_values($out);
    }

    private function text(array $response): string
    {
        return trim(collect($response['content'] ?? [])->where('type', 'text')->pluck('text')->implode(''));
    }
}
