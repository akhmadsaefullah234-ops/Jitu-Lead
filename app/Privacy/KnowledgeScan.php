<?php

namespace App\Privacy;

use App\Models\AiKnowledgeItem;
use Illuminate\Support\Collection;

/**
 * The agency's knowledge entries that hold a buyer's personal data, for the red warning in AI > Pengetahuan and the
 * ai:scan-knowledge command. Must run inside the tenant.
 */
class KnowledgeScan
{
    /**
     * @param  bool  $activeOnly  only the entries the AI still uses
     * @return Collection<int, array{item: AiKnowledgeItem, findings: list<string>}>
     */
    public static function flagged(bool $activeOnly = true): Collection
    {
        $keep = PersonalData::keep();

        return AiKnowledgeItem::query()->when($activeOnly, fn ($q) => $q->where('active', true))->with('property')->orderBy('id')->get()
            ->map(fn (AiKnowledgeItem $item) => ['item' => $item, 'findings' => PersonalData::inKnowledge($item, $keep)])
            ->filter(fn (array $row) => $row['findings'] !== [])
            ->values();
    }

    /** @return list<int> the ids of the flagged entries */
    public static function ids(bool $activeOnly = true): array
    {
        return self::flagged($activeOnly)->map(fn (array $row) => $row['item']->getKey())->all();
    }
}
