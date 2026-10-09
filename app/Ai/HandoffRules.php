<?php

namespace App\Ai;

/**
 * Messages the assistant must not answer by itself: the client asks for a
 * person, complains, or haggles. Agencies add their own words on top.
 */
class HandoffRules
{
    public const DEFAULTS = [
        'bicara dengan manusia', 'bicara dengan orang', 'bicara dengan agen', 'bicara langsung',
        'telepon saya', 'tolong telepon', 'hubungi saya', 'komplain', 'keluhan', 'kecewa',
        'refund', 'pengembalian dana', 'nego', 'tawar', 'diskon', 'penipuan',
    ];

    public function matches(string $text, ?string $custom): bool
    {
        $text = mb_strtolower($text);

        foreach (array_merge(self::DEFAULTS, $this->parse($custom)) as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function parse(?string $custom): array
    {
        return array_values(array_filter(array_map(
            fn (string $w) => mb_strtolower(trim($w)),
            preg_split('/[,\n]+/', (string) $custom) ?: [],
        ), fn (string $w) => mb_strlen($w) >= 3));
    }
}
