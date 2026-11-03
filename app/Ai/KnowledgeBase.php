<?php

namespace App\Ai;

use App\Models\AiKnowledgeItem;

/**
 * Picks what the agency has taught the assistant. An agency's notes are small
 * enough to send whole; when they are not, the entries that share the most
 * words with the client's question go first and the rest are cut. Entries
 * written for one product only go to chats about that product, ahead of the rest.
 */
class KnowledgeBase
{
    public const BUDGET = 12000;

    /** @param  int|null  $productId  the product the chat is about: its entries are sent with the general ones, other products' never */
    public function context(string $question, ?int $productId = null): string
    {
        $words = $this->words($question);

        $items = AiKnowledgeItem::query()->where('active', true)
            ->where(fn ($q) => $q->whereNull('property_id')->when($productId !== null, fn ($q) => $q->orWhere('property_id', $productId)))->get()
            ->sortByDesc(fn (AiKnowledgeItem $i) => ($i->property_id !== null ? 1000 : 0) + count(array_intersect($words, $this->words($i->title.' '.$i->question.' '.$i->content))))
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
