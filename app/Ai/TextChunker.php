<?php

namespace App\Ai;

/**
 * Splits a long document into pieces the assistant can pick from, cutting at
 * blank lines and sentence ends so a price never lands apart from its unit.
 */
class TextChunker
{
    public function __construct(private int $size = 3000) {}

    /** @return list<string> */
    public function chunk(string $text): array
    {
        $chunks = [];
        $current = '';

        foreach (preg_split('/\n{2,}/', $text) ?: [] as $paragraph) {
            foreach ($this->pieces(trim($paragraph)) as $piece) {
                if ($piece === '') {
                    continue;
                }

                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $this->size) {
                    $chunks[] = $current;
                    $current = '';
                }

                $current = $current === '' ? $piece : $current."\n\n".$piece;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** @return list<string> */
    private function pieces(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= $this->size) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/(?<=[.!?\n])\s+/u', $paragraph) ?: [] as $sentence) {
            while (mb_strlen($sentence) > $this->size) {
                $pieces[] = mb_substr($sentence, 0, $this->size);
                $sentence = mb_substr($sentence, $this->size);
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $this->size) {
                $pieces[] = $current;
                $current = '';
            }

            $current = $current === '' ? $sentence : $current.' '.$sentence;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
