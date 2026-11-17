<?php

namespace App\Ai;

use App\Models\AiKnowledgeItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the AI consistent: a question it answered from the knowledge is remembered (per agency, for as long as the
 * knowledge stays the same), so when the same question comes again and the model wants to hand it to a person,
 * it is asked once more with its earlier answer in view. The same question is then not answered one day and
 * handed off the next. Must run inside the tenant.
 */
final class AnswerMemory
{
    public const DAYS = 14;

    /** Shorter texts ("ok", "halo") are not questions worth remembering. */
    private const MIN_LENGTH = 8;

    public static function remember(Tenant $tenant, string $question, string $answer): void
    {
        if (($key = self::key($tenant, $question)) !== null && trim($answer) !== '') {
            Cache::put($key, mb_substr(trim($answer), 0, 1000), now()->addDays(self::DAYS));
        }
    }

    /** The earlier answer to the same question while the knowledge is unchanged, or null. */
    public static function previous(Tenant $tenant, string $question): ?string
    {
        $key = self::key($tenant, $question);

        return $key !== null ? Cache::get($key) : null;
    }

    /** Lower case, letters and digits only, single spaces: "Harganya berapa kak??" = "harganya berapa kak". */
    public static function normalize(string $question): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($question))));
    }

    private static function key(Tenant $tenant, string $question): ?string
    {
        $text = self::normalize($question);

        if (mb_strlen($text) < self::MIN_LENGTH) {
            return null;
        }

        // A change to the knowledge (an edit, a new item, one switched off) starts afresh.
        $knowledge = AiKnowledgeItem::query()->where('active', true)->selectRaw('count(*) as n, max(updated_at) as at')->first();

        return 'ai-answer:'.$tenant->getKey().':'.sha1($text.'|'.$knowledge?->n.'|'.$knowledge?->at);
    }
}
