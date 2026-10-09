<?php

namespace App\Ai;

/**
 * What every new agency starts with: ready-to-use instructions and handoff
 * words, stored on the tenant so admins can read and change them. The hard
 * rules (answer only from the knowledge, hand off when unsure) stay in code.
 */
class Defaults
{
    public const INSTRUCTIONS = <<<'TXT'
Sapa dengan "Kak" dan gunakan nama klien bila diketahui.
Jawab ramah, singkat, dan langsung ke inti. Satu pertanyaan klien dijawab satu hal dulu.
Setelah menjawab, ajukan satu pertanyaan ringan agar percakapan berlanjut, misalnya budget, lokasi yang diminati, atau rencana cara bayar (tunai, cicilan developer, atau KPR).
Bila klien sudah menunjukkan minat, tawarkan jadwal survei lokasi.
Jangan menyebut harga, diskon, atau ketersediaan unit yang tidak tertulis di pengetahuan. Jangan menjanjikan persetujuan KPR.
Jangan meminta data pribadi sensitif seperti nomor KTP atau PIN lewat chat.
TXT;

    /** @return list<string> */
    public static function keywords(): array
    {
        return HandoffRules::DEFAULTS;
    }

    public static function keywordText(): string
    {
        return implode(', ', self::keywords());
    }
}
