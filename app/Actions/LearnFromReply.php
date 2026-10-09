<?php

namespace App\Actions;

use App\Models\AiKnowledgeItem;
use App\Models\AiSuggestion;
use App\Models\WaConversation;
use App\Models\WaMessage;

/**
 * When an agent answers a client's question by hand, keep the pair as a
 * suggestion. Nothing reaches the assistant until an admin approves it, so a
 * wrong or private reply never becomes "knowledge" by accident.
 */
class LearnFromReply
{
    public const WINDOW_HOURS = 2;

    public function __invoke(WaConversation $conversation, WaMessage $sent): ?AiSuggestion
    {
        $reply = trim((string) $sent->body);

        if (mb_strlen($reply) < 15) {
            return null;
        }

        $question = $conversation->messages()->reorder()->where('direction', 'in')->where('type', 'text')
            ->whereNotNull('body')->where('sent_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderByDesc('id')->first();

        if ($question === null || mb_strlen(trim((string) $question->body)) < 8) {
            return null;
        }

        // Only the first human answer to a question counts, not every follow-up message.
        $answered = $conversation->messages()->reorder()->where('direction', 'out')->where('id', '>', $question->getKey())->whereKeyNot($sent->getKey())->exists();

        if ($answered) {
            return null;
        }

        $text = mb_substr(trim((string) $question->body), 0, 500);

        $exists = AiSuggestion::query()->whereRaw('lower(question) = ?', [mb_strtolower($text)])->exists()
            || AiKnowledgeItem::query()->whereRaw('lower(question) = ?', [mb_strtolower($text)])->exists();

        if ($exists) {
            return null;
        }

        return AiSuggestion::query()->create([
            'lead_id' => $conversation->lead_id,
            'question' => $text,
            'answer' => mb_substr($reply, 0, 2000),
        ]);
    }
}
