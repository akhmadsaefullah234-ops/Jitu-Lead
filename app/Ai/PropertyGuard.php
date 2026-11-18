<?php

namespace App\Ai;

use App\Models\AiKnowledgeItem;

/**
 * The last check on a property agency's automatic reply: money, percentages, years and areas the answer names must
 * come from what the model was given (PENGETAHUAN, the lead, the chat, tool results), and none of the agency's
 * forbidden phrases ("siap huni", "pasti disetujui") may be promised. The property counterpart of
 * ShopTools::inventsShippingCost. A phrase that is denied right next to it ("belum SHM", "SHM belum tersedia") is
 * not a promise and passes.
 */
final class PropertyGuard
{
    /** "Rp 10 juta", "600 jutaan", "1,5 M", "Rp1.200.000.000", "2 miliar". */
    private const MONEY = '/(?:(rp\.?)\s*)?(?<![\d.,])(\d+(?:[.,]\d+)*)\s*(ribu|rb|k|juta|jutaan|jt|miliar|milyar|triliun|(?-i:M|T))?(?![\p{L}\d])/iu';

    private const PERCENT = '/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:%|persen\b)/iu';

    private const YEAR = '/(?<![\d.,])((?:19|20)\d{2})(?![\d.,])/u';

    private const DURATION = '/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:tahun|thn)\b/iu';

    private const AREA = '/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:m2|m²|meter\s+persegi|meter|are|hektar|ha)(?![\p{L}\d])/iu';

    private const DENIAL = '(?:belum|tidak|tdk|bukan|tanpa|blm|gak|nggak|ga)';

    /** A "no" that governs what follows it in the clause: "kami tidak menawarkan tanpa DP atau DP 0". */
    private const NEGATION = '(?:belum|tidak|tdk|bukan|blm|gak|nggak)';

    /** What a negation is about when the phrase comes after it: offering, having, allowing, promising. */
    private const NEGATED_VERBS = 'ada|bisa|dapat|pakai|memakai|dengan|termasuk|menawarkan|ditawarkan|melayani|menyediakan|menerima|berlaku|mendukung|tersedia|menjanjikan|menjamin|memberikan|memberi';

    /** "SHM belum tersedia", "tanpa DP tidak bisa ya Kak": a no right after the phrase (not "tidak perlu", "tanpa bunga"). */
    private const DENIED_AFTER = '/^[\s\/,]*(?:\p{L}+\s+)?(?:belum|tidak|tdk|bukan|blm|gak|nggak)\b(?!\s+(?:perlu|usah|lama|kalah|hanya|cuma|sekadar|sekedar|ribet|repot|mahal))/iu';

    /** Not knowing, anywhere in the sentence: "saya belum punya info pastinya", "belum bisa dipastikan". */
    private const UNSURE = '(?:\b(?:belum|blm|tidak|tdk|gak|nggak)\s+(?:punya|ada|tahu|memegang|mendapat|dapat)\b[^.!?\n]{0,30}\b(?:info|informasi|data|kepastian|pastinya|kabar)\b|\b(?:tidak|tdk|belum|blm)\s+(?:bisa|dapat)\s+(?:memastikan|dipastikan|menjamin|dijamin|menjanjikan)\b|\bbelum\s+(?:bisa\s+)?(?:dipastikan|pasti)\b|\b(?:saya|kami)\s+(?:cek|cekkan|tanyakan|konfirmasi|konfirmasikan)\s+(?:dulu|ke\s+tim|ke\s+developer|ke\s+kantor))';

    /** Not knowing, in the same clause: "belum punya info soal", "tidak bisa memastikan", "perlu saya cek dulu". */
    private const DOUBT = '(?:\\b(?:belum|blm)\\b|\\b(?:tidak|tdk|gak|nggak|kurang)\\s+(?:bisa|dapat|tahu|punya|yakin|ada\\s+info)\\b|\\b(?:memastikan|dipastikan|konfirmasi|mengecek|dicek|cek\\s+dulu|tanyakan|info(?:rmasi)?\\s+(?:soal|tentang|mengenai))\\b)';

    /** Any number, whatever follows it: "Bangunan 38" grounds "38 m2". */
    private const BARE = '/(?<![\d.,])(\d+(?:[.,]\d+)?)(?![\d])/u';

    /** "36-45 m2", "Rp600 sampai 700 juta", "5 s/d 10 tahun": a range, with money or a unit so dates and phone numbers are left alone. */
    private const RANGE = '/(rp\.?\s*)?(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:-|–|—|s\.?\/?d\.?|sampai|hingga|sd)\s*(?:rp\.?\s*)?(\d+(?:[.,]\d+)?)\s*(ribu|rb|juta|jutaan|jt|miliar|milyar|M|m2|m²|meter|tahun|thn|bulan|%|persen)?(?![\p{L}\d])/iu';

    /**
     * Why the answer may not go out on its own, or [] when it may.
     *
     * A percentage, year, duration or area counts as grounded when the same number is in the sources, whatever unit
     * is written next to it there ("38 m2" against "Bangunan 38"); a range must be in what was given as a range. A
     * forbidden phrase the agency's own knowledge states ("Gratis biaya KPR") is a fact, not a promise.
     *
     * @param  string  $given  everything the model was given and may repeat: system prompt, chat, tool results
     * @param  list<string>  $phrases  the agency's forbidden phrases
     * @param  string|null  $sources  the agency's knowledge; bare numbers are matched here (else in $given)
     * @param  list<string>  $granted  phrases the agency's structured facts make true (PaymentModel::granted)
     * @return list<string>
     */
    public static function problems(string $answer, string $given, array $phrases, ?string $sources = null, array $granted = []): array
    {
        $problems = [];
        $known = self::money($given);

        foreach (self::money($answer) as $text => $value) {
            if (! in_array($value, $known, true)) {
                $problems[] = "angka rupiah \"{$text}\" tidak ada di pengetahuan atau data";
            }
        }

        $bare = array_values(self::numbers(self::BARE, ($sources ?? $given)."\n".$given));

        foreach ([self::PERCENT => 'persentase', self::YEAR => 'tahun', self::DURATION => 'jangka waktu', self::AREA => 'luas'] as $pattern => $what) {
            $allowed = [...array_values(self::numbers($pattern, $given)), ...($pattern === self::YEAR ? [] : $bare)];

            foreach (self::numbers($pattern, $answer) as $text => $value) {
                if (! in_array($value, $allowed, true)) {
                    $problems[] = "{$what} \"{$text}\" tidak ada di pengetahuan atau data";
                }
            }
        }

        $ranges = self::ranges($given."\n".($sources ?? ''));

        foreach (self::ranges($answer) as $text => $pair) {
            if (! in_array($pair, $ranges, true)) {
                $problems[] = "rentang \"{$text}\" tidak ada di pengetahuan atau data";
            }
        }

        $granted = array_map(fn (string $g) => mb_strtolower(trim($g)), $granted);

        foreach ($phrases as $phrase) {
            if (in_array(mb_strtolower(trim($phrase)), $granted, true) || ($sources !== null && self::promises($sources, $phrase))) {
                continue;
            }

            if (self::promises($answer, $phrase)) {
                $problems[] = "frasa terlarang \"{$phrase}\"";
            }
        }

        return array_values(array_unique($problems));
    }

    /** @return array<string, int> each amount as written => its value in rupiah */
    public static function money(string $text): array
    {
        preg_match_all(self::MONEY, $text, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $match) {
            [$whole, $rp, $number, $unit] = $match + [1 => '', 2 => '', 3 => ''];
            $unit = mb_strtolower($unit);

            // A bare number is not money ("2 kamar", "tipe 45"): only with "Rp" or a money unit.
            if ($rp === '' && $unit === '') {
                continue;
            }

            $multiplier = match ($unit) {
                'ribu', 'rb', 'k' => 1_000, 'juta', 'jutaan', 'jt' => 1_000_000, 'miliar', 'milyar', 'm' => 1_000_000_000, 'triliun', 't' => 1_000_000_000_000, default => 1,
            };
            $value = $multiplier === 1
                ? (int) preg_replace('/[.,]/', '', $number)
                : (int) round((float) str_replace(',', '.', (string) preg_replace('/\.(?=\d{3}\b)/', '', $number)) * $multiplier);

            if ($value >= 1_000) {
                $out[trim($whole)] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, string> each range with money or a unit, as written => "low-high" */
    private static function ranges(string $text): array
    {
        preg_match_all(self::RANGE, $text, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $match) {
            if (trim($match[1]) === '' && ($match[4] ?? '') === '') {
                continue;
            }

            $out[trim($match[0])] = str_replace(',', '.', $match[2]).'-'.str_replace(',', '.', $match[3]);
        }

        return $out;
    }

    /** The agency's active knowledge, all of it: what a forbidden phrase or a bare number may be grounded in. */
    public static function sources(): string
    {
        return AiKnowledgeItem::query()->where('active', true)->get(['title', 'question', 'content'])
            ->map(fn (AiKnowledgeItem $i) => trim($i->title."\n".$i->question."\n".$i->content))->implode("\n\n");
    }

    /** @return array<string, string> each number as written => the number alone, "1,5" and "1.5" alike */
    private static function numbers(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $match) {
            $out[trim($match[0])] = str_replace(',', '.', $match[1]);
        }

        return $out;
    }

    /**
     * Whether the answer states the phrase as true. A phrase is not a statement when its sentence denies or doubts
     * it: "belum SHM", "SHM belum tersedia", "kami tidak menawarkan tanpa DP atau DP 0", "belum punya info soal siap
     * huni", "status siap huni saya belum punya info pastinya", "DP 0 belum bisa kami berikan". "Unit ini siap huni"
     * and "DP 0 tanpa bunga" are.
     */
    public static function states(string $answer, string $phrase): bool
    {
        $quoted = preg_quote(trim($phrase), '/');
        $found = preg_match_all('/(?<![\p{L}\d])'.$quoted.'(?![\p{L}\d])/iu', $answer, $m, PREG_OFFSET_CAPTURE);

        if (! $found) {
            return false;
        }

        foreach ($m[0] as [$text, $offset]) {
            $before = mb_strtolower(substr($answer, max(0, $offset - 40), min(40, $offset)));
            $after = mb_strtolower(substr($answer, $offset + strlen($text), 40));
            // The clause the phrase is in, up to the last sentence mark or comma before it.
            $clause = mb_strtolower((string) preg_replace('/^.*[.!?,;:\n]/su', '', substr($answer, max(0, $offset - 80), min(80, $offset))));
            // The whole sentence, for a doubt written after the phrase ("Status siap huni ... saya belum punya info").
            $sentence = mb_strtolower((string) preg_replace('/^.*[.!?\n]/su', '', substr($answer, max(0, $offset - 160), min(160, $offset)))
                .$text.preg_replace('/[.!?\n].*$/su', '', substr($answer, $offset + strlen($text), 160)));

            $denied = preg_match('/(?<![\p{L}\d])'.self::DENIAL.'(?:\s+(?:'.self::NEGATED_VERBS.'|ada\s+yang))?\s*$/iu', $before)
                || preg_match(self::DENIED_AFTER, $after)
                || preg_match('/'.self::DOUBT.'/iu', $clause)
                || preg_match('/(?<![\p{L}\d])'.self::NEGATION.'\s+(?:\p{L}+\s+)?(?:'.self::NEGATED_VERBS.')\b/iu', $clause)
                || preg_match('/'.self::UNSURE.'/iu', $sentence);

            if (! $denied) {
                return true;
            }
        }

        return false;
    }

    private static function promises(string $answer, string $phrase): bool
    {
        return self::states($answer, $phrase);
    }
}
