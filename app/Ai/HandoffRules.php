<?php

namespace App\Ai;

/**
 * Messages the assistant must not answer by itself: the client asks for a
 * person, complains, or haggles. The agency's list lives on its tenant and starts as these words.
 */
class HandoffRules
{
    public const DEFAULTS = [
        'bicara dengan manusia', 'bicara dengan orang', 'bicara dengan agen', 'bicara langsung',
        'telepon saya', 'tolong telepon', 'hubungi saya', 'komplain', 'keluhan', 'kecewa',
        'refund', 'pengembalian dana', 'nego', 'tawar', 'diskon', 'penipuan',
    ];

    /**
     * @param  ?string  $configured  The agency's own list. Blank means it was never set, so the defaults apply.
     */
    public function matches(string $text, ?string $configured): bool
    {
        $text = mb_strtolower($text);
        $words = $this->parse($configured);

        foreach ($words ?: self::DEFAULTS as $word) {
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
