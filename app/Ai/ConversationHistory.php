<?php

namespace App\Ai;

use App\Actions\GenerateAiReply;
use App\Models\WaConversation;
use App\WhatsApp\MessageContent;

/**
 * The newest messages of a chat as the AI reads them word for word: as many as fit a token budget
 * (config jitu.ai.history_messages and history_tokens), newest first. What no longer fits is not dropped
 * without a trace: it is counted, so GenerateAiReply can have it summarised (ConversationMemory) first.
 *
 * The client's words are untrusted: they only ever appear as user turns, never in the system prompt.
 */
final class ConversationHistory
{
    /** Message types that are never part of what the AI reads. */
    public const SKIPPED = ['template', 'reaction', 'unsupported'];

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $turns
     * @param  int  $omitted  older messages that did not fit and that the chat summary does not cover yet
     */
    private function __construct(public readonly array $turns, public readonly int $omitted) {}

    /** Tokens a text costs, estimated high: Indonesian chat runs about three to four characters a token. */
    public static function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3);
    }

    /**
     * @param  list<int>  $handedOff  inbound messages already handed to a person; each is followed by $handedOffTurn
     */
    public static function load(WaConversation $conversation, array $handedOff = [], string $handedOffTurn = ''): self
    {
        $max = max(1, (int) config('jitu.ai.history_messages', 40));
        $budget = max(1, (int) config('jitu.ai.history_tokens', 6000));
        $chars = max(100, (int) config('jitu.ai.message_chars', 1500));

        // The night greeting ("kami balas besok pagi") is not part of the chat the AI answers in the morning.
        $newest = $conversation->messages()->whereNotIn('type', self::SKIPPED)
            ->where(fn ($q) => $q->whereNull('origin')->orWhere('origin', '!=', GenerateAiReply::QUIET_ORIGIN))
            ->reorder()->orderByDesc('id')->limit($max)->get();
        $kept = [];
        $used = 0;
        $oldest = null;

        foreach ($newest as $m) {
            // Voice notes, locations and stickers as words; a sticker or a reaction is only context, never a question.
            $content = MessageContent::forAi($m);

            if ($content === null || $content === '') {
                $oldest = $m->getKey();

                continue;
            }

            $content = mb_substr($content, 0, $chars);

            if ($m->direction === 'out' && filled($m->reactions['in'] ?? null)) {
                $content .= ' (pelanggan memberi reaksi '.$m->reactions['in'].')';
            }

            $cost = self::tokens($content);

            // The newest message is always read; older ones only while they fit.
            if ($kept !== [] && $used + $cost > $budget) {
                break;
            }

            $used += $cost;
            $oldest = $m->getKey();
            $kept[] = [$m, $content];
        }

        $turns = [];

        foreach (array_reverse($kept) as [$m, $content]) {
            $role = $m->direction === 'in' ? 'user' : 'assistant';

            if ($turns && end($turns)['role'] === $role) {
                $turns[array_key_last($turns)]['content'] .= "\n".$content;
            } else {
                $turns[] = ['role' => $role, 'content' => $content];
            }

            if ($role === 'user' && in_array((int) $m->getKey(), $handedOff, true)) {
                $turns[] = ['role' => 'assistant', 'content' => $handedOffTurn];
            }
        }

        // The API takes a chat that starts and ends with the client (see AnthropicClient::normalize).
        $turns = AnthropicClient::normalize($turns);

        $omitted = $oldest === null ? 0 : $conversation->messages()->whereNotIn('type', self::SKIPPED)
            ->where('id', '<', $oldest)->where('id', '>', (int) $conversation->ai_memory_message_id)->count();

        return new self($turns, $omitted);
    }
}
