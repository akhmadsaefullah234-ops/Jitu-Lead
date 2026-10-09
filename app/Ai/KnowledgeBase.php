<?php

namespace App\Ai;

use App\Models\AiKnowledgeItem;

/**
 * Picks what the agency has taught the assistant. An agency's notes are small
 * enough to send whole; when they are not, the entries that share the most
 * words with the client's question go first and the rest are cut.
 */
class KnowledgeBase
{
    public const BUDGET = 12000;

    public function context(string $question): string
    {
        $words = $this->words($question);

        $items = AiKnowledgeItem::query()->where('active', true)->get()
            ->sortByDesc(fn (AiKnowledgeItem $i) => count(array_intersect($words, $this->words($i->title.' '.$i->question.' '.$i->content))))
            ->values();

        $out = '';

        foreach ($items as $item) {
            $entry = $item->question
                ? "Tanya: {$item->question}\nJawab: {$item->content}"
                : "{$item->title}:\n{$item->content}";
            $entry = mb_substr($entry, 0, 4000)."\n\n";

            if (mb_strlen($out) + mb_strlen($entry) > self::BUDGET) {
                break;
            }

            $out .= $entry;
        }

        return trim($out);
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [],
            fn (string $w) => mb_strlen($w) >= 4,
        )));
    }
}
