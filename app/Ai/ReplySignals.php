<?php

namespace App\Ai;

/**
 * What the AI tells us besides the text for the buyer, kept apart from that text: handing the chat to a person
 * and "no reply needed" are tool calls, never words in the answer. Also the checks that keep internal text
 * (reasoning about rules or knowledge, the word HANDOFF, tool names) from ever reaching a buyer, and the short
 * acknowledgements ("ok", "siap", 🙏) that never need a person.
 */
class ReplySignals
{
    public const HANDOFF_TOOL = 'serahkan_ke_admin';

    public const SILENT_TOOL = 'tanpa_balasan';

    /** The old text signal, still understood anywhere in an answer: such an answer is never sent. */
    public const HANDOFF_WORD = 'HANDOFF';

    /** Words a short acknowledgement is made of (after lowercasing; punctuation and emoji removed). */
    private const ACK_WORDS = [
        'ok', 'oke', 'okey', 'okay', 'okee', 'oce', 'okeh', 'okok', 'k', 'sip', 'siap', 'ya', 'iya', 'y', 'yup', 'yap', 'baik', 'baiklah', 'noted',
        'sebentar', 'bentar', 'tunggu', 'nanti', 'dulu', 'deal', 'mantap', 'mantab', 'setuju', 'boleh', 'lanjut', 'gas',
        'terima', 'kasih', 'makasih', 'trims', 'thanks', 'thank', 'you', 'thx', 'tq', 'ty', 'tengkyu', 'suwun', 'nuwun', 'matur',
        'kak', 'ka', 'kakak', 'min', 'gan', 'sis', 'mbak', 'mba', 'mas', 'bang', 'pak', 'bu', 'ibu', 'yah', 'deh', 'dong', 'aja', 'saja', 'nya',
    ];

    /** Signs that an answer talks about its own instructions instead of to the buyer. */
    private const INTERNAL = [
        '/\bHANDOFF\b/i',
        '/\b(?:'.self::HANDOFF_TOOL.'|'.self::SILENT_TOOL.'|'.ShopTools::CHECK.'|'.ShopTools::RECORD.')\b/i',
        '/\b(?:berdasarkan|sesuai(?:\s+dengan)?|menurut|mengikuti)\s+(?:aturan|instruksi|panduan|pedoman|arahan|prompt|pengetahuan|data\s+pengetahuan|informasi\s+yang\s+(?:saya|aku)\s+(?:miliki|punya))\b/iu',
        '/\b(?:aturan|instruksi|arahan)\s+(?:saya|sistem|yang\s+diberikan|yang\s+ada)\b/iu',
        '/\b(?:system\s+prompt|prompt\s+sistem|bagian\s+pengetahuan|catatan\s+sistem|PENGETAHUAN:|tool\s+call)\b/iu',
        '/\b(?:saya|aku)\s+(?:perlu|harus|akan|sebaiknya)\s+(?:memanggil|menjalankan)\b|\b(?:saya|aku)\s+(?:perlu|harus|sebaiknya)\s+(?:menggunakan|memakai|menyerahkan|mengalihkan)\b/iu',
        '/(?:^|[.!?\n]\s*)(?:pembeli|pelanggan|klien|customer|user)\s+(?:ini\s+)?(?:menanyakan|bertanya|meminta|ingin|mengirim|memilih)\b/iu',
        '/<\/?[a-z_]{2,}[^>]*>/i',
    ];

    /** @return list<array{name: string, description: string, input_schema: array}> */
    public static function definitions(): array
    {
        return [
            [
                'name' => self::HANDOFF_TOOL,
                'description' => 'Serahkan chat ini ke admin manusia. Pakai bila pertanyaan pembeli tidak terjawab oleh PENGETAHUAN atau hasil tool, '
                    .'atau pembeli meminta nego, komplain, atau bicara dengan orang. Jangan menulis teks apa pun bila memakai tool ini.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'alasan' => ['type' => 'string', 'description' => 'Alasan singkat untuk admin (tidak dikirim ke pembeli).'],
                ], 'required' => ['alasan']],
            ],
            [
                'name' => self::SILENT_TOOL,
                'description' => 'Tidak perlu membalas: pesan terakhir pembeli hanya tanda setuju, terima kasih, emoji, atau "sebentar" dan tidak ada yang perlu dijawab.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    /** The rules about these signals, for the system prompt. */
    public static function instructions(): string
    {
        return implode("\n", [
            'Jika pertanyaan tidak terjawab oleh PENGETAHUAN atau hasil tool, atau klien meminta nego, komplain, atau bicara dengan orang, panggil tool '.self::HANDOFF_TOOL.' dan jangan menulis teks apa pun.',
            'Jawabanmu dikirim apa adanya ke pembeli. Tulis hanya pesan untuk pembeli: jangan menjelaskan aturan, instruksi, pengetahuan, tool, atau alasanmu, dan jangan menyebut pembeli sebagai orang ketiga.',
        ]);
    }

    /** @param  list<array{name: string, input: array, result: array}>  $calls */
    public static function handoff(array $calls): ?string
    {
        foreach ($calls as $call) {
            if ($call['name'] === self::HANDOFF_TOOL) {
                return mb_substr(trim((string) ($call['input']['alasan'] ?? '')), 0, 200);
            }
        }

        return null;
    }

    /** @param  list<array{name: string, input: array, result: array}>  $calls */
    public static function silent(array $calls): bool
    {
        return collect($calls)->contains(fn (array $c) => $c['name'] === self::SILENT_TOOL);
    }

    /** The answer asks for a person in words ("HANDOFF" anywhere): it is never sent. */
    public static function saysHandoff(string $answer): bool
    {
        return preg_match('/\b'.self::HANDOFF_WORD.'\b/i', $answer) === 1;
    }

    /** True when the answer talks about rules, knowledge, instructions or tools, or holds a markup tag. */
    public static function internal(string $answer): bool
    {
        foreach (self::INTERNAL as $pattern) {
            if (preg_match($pattern, $answer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A short acknowledgement or agreement that never needs a person: "ok", "oke kak", "siap", "sebentar ya",
     * "terima kasih", "Ok deal", an emoji alone.
     */
    public static function acknowledgement(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        if ($text === '' || mb_strlen($text) > 40 || preg_match('/[?\d]/u', $text)) {
            return false;
        }

        $words = preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === []) {
            // Only emoji or punctuation.
            return preg_match('/[\p{So}\p{Sk}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text) === 1;
        }

        // "okee", "siapp", "yaaa": letters repeated for warmth.
        $words = array_map(fn ($w) => preg_replace('/(\p{L})\1+$/u', '$1', $w), $words);
        $known = array_map(fn ($w) => preg_replace('/(\p{L})\1+$/u', '$1', $w), self::ACK_WORDS);

        return count($words) <= 5 && array_diff($words, $known) === [];
    }
}
