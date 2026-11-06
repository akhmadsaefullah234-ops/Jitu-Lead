<?php

namespace App\Actions;

use App\Models\AiKnowledgeItem;
use App\Models\AiSuggestion;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Privacy\PersonalData;

/**
 * When an agent answers a client's question by hand, keep the pair as a
 * suggestion. Nothing reaches the assistant until an admin approves it, so a
 * wrong or private reply never becomes "knowledge" by accident.
 *
 * Only general questions are kept: an order, a form or an address (a buyer's name, number or address in the
 * question) is one buyer's business, not an example for the next one. What is kept is cleaned of personal data
 * first: the buyer's name in the answer, a city, a number become placeholders ("[nama]", "[kota]").
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

        $keep = PersonalData::keep();

        if (PersonalData::isPersonalMessage((string) $question->body, $keep)) {
            return null;
        }

        ['question' => $text, 'answer' => $reply] = PersonalData::scrubPair(mb_substr(trim((string) $question->body), 0, 500), $reply, $keep);

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
