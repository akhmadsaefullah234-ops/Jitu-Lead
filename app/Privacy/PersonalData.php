<?php

namespace App\Privacy;

use App\Models\AiKnowledgeItem;
use App\Regions\RegionDirectory;
use App\Shipping\Regions;
use App\Shipping\ShippingSettings;
use App\Support\CurrentTenant;
use Throwable;

/**
 * A buyer's personal data in text: their name, phone number, address, city, e-mail and bank account. Knowledge
 * the AI learns from agents' replies is cleaned of it before it is kept (#54: buyer A's order, name and address
 * were kept as an example, and the AI greeted buyer B as buyer A), old knowledge is scanned for it, and an AI
 * answer is checked for names and places that belong to someone else.
 *
 * What the agency itself wrote elsewhere (its name, its own knowledge, its warehouse city) is "kept": the store's
 * own WhatsApp number or account in an answer is not a buyer's.
 */
class PersonalData
{
    public const NAME = '[nama]';

    public const PHONE = '[nomor HP]';

    public const ADDRESS = '[alamat]';

    public const CITY = '[kota]';

    public const EMAIL = '[email]';

    public const ACCOUNT = '[nomor rekening]';

    /** What each placeholder means, for the warnings an admin reads. */
    public const LABELS = [
        self::NAME => 'nama pembeli',
        self::PHONE => 'nomor HP',
        self::ADDRESS => 'alamat',
        self::CITY => 'kota/kabupaten',
        self::EMAIL => 'e-mail',
        self::ACCOUNT => 'nomor rekening',
    ];

    /** Placeholders a general question never holds: a question that needs them is one buyer's order or form. */
    public const NOT_GENERAL = [self::NAME, self::PHONE, self::ADDRESS, self::EMAIL, self::ACCOUNT];

    /** Words that are not a name, after "saya" or a greeting ("saya juga mau order", "Kak Terima kasih"). */
    private const NOT_NAMES = [
        'juga', 'sudah', 'udah', 'mau', 'ingin', 'pengen', 'pingin', 'lagi', 'ya', 'yg', 'yang', 'kak', 'kakak', 'min', 'admin', 'gan', 'sis', 'sih', 'tadi',
        'kemarin', 'baru', 'dulu', 'pernah', 'masih', 'tetap', 'cuma', 'hanya', 'sekarang', 'mo', 'mw', 'jadi', 'tidak', 'nggak', 'gak', 'ga', 'belum', 'bisa',
        'boleh', 'minta', 'tanya', 'mohon', 'tolong', 'terima', 'makasih', 'silakan', 'silahkan', 'untuk', 'kami', 'saya', 'aku', 'ada', 'ini', 'itu', 'baik',
        'siap', 'oke', 'ok', 'okey', 'jika', 'kalau', 'bila', 'semoga', 'selamat', 'pesanan', 'pesanannya', 'ongkir', 'harga', 'total', 'barang', 'paket',
        'sebelumnya', 'dan', 'atau', 'nanti', 'langsung', 'cek', 'apakah', 'apa', 'berapa', 'kapan', 'dimana', 'gimana', 'bagaimana', 'cod', 'transfer',
        'halo', 'hai', 'hallo', 'pagi', 'siang', 'sore', 'malam', 'mohon', 'maaf', 'sama', 'dengan', 'dari', 'di', 'ke', 'order', 'pesan', 'beli', 'seperti',
        'agar', 'supaya', 'karena', 'sebentar', 'kirim', 'dikirim', 'stok', 'ready', 'warna', 'ukuran', 'size', 'promo', 'free', 'gratis', 'semua', 'bapak',
        'ibu', 'pak', 'bu', 'mas', 'mbak', 'mba', 'bang', 'om', 'tante', 'kk', 'ka', 'tuh', 'dong', 'deh', 'kok', 'nih', 'loh', 'lho', 'pun', 'akan', 'sedang',
        'tunggu', 'kurang', 'tau', 'tahu', 'lihat', 'liat', 'cari', 'coba', 'perlu', 'butuh', 'sangat', 'benar', 'betul', 'tertarik', 'jadi', 'kurir', 'resi',
    ];

    /** One-word city and regency names that are also everyday words: only a place after "Kota" or "Kab.". */
    private const COMMON_WORDS = [
        'batu', 'metro', 'batang', 'pati', 'palu', 'tual', 'ende', 'banjar', 'agam', 'buru', 'jaya', 'kaur', 'lahat', 'sigi', 'alor', 'bone', 'malaka',
        'muna', 'solok', 'tegal', 'lima', 'seram', 'kota', 'barat', 'timur', 'selatan', 'utara', 'tengah', 'kepulauan', 'pesisir', 'raya', 'baru',
    ];

    /** After "kota" / "kab." these are not a place name ("kota mana", "kota tujuan"). */
    private const NOT_PLACE_AFTER = ['mana', 'apa', 'tujuan', 'kakak', 'kak', 'anda', 'kamu', 'asal', 'besar', 'lain', 'yang', 'ini', 'itu', 'tersebut', 'sama', 'nya', 'kecil', 'saya', 'kami'];

    private const HONORIFICS = 'kak|ka|kakak|kk|mas|mbak|mba|bang|bu|ibu|pak|bapak|om|tante|sis|gan|cik|ci|ko|koh|teh|aa|akang|uda|uni|bunda|bund';

    private const NAME_WORD = "[\\p{L}][\\p{L}'.]*";

    /**
     * The text with every piece of a buyer's personal data replaced by a placeholder.
     *
     * @param  string  $keep  what the agency itself wrote: names, numbers and places in it are left as they are
     * @param  list<string>  $names  names already known to be the buyer's (from the question), removed wherever they appear
     */
    public static function scrub(string $text, string $keep = '', array $names = []): string
    {
        return self::analyse($text, $keep, $names)['text'];
    }

    /**
     * Cleans a learned question and its answer together: a name the buyer gave in the question ("saya zainal
     * abidin mau order") is also taken out of the answer ("Baik kak Zainal").
     *
     * @return array{question: string, answer: string, findings: list<string>}
     */
    public static function scrubPair(string $question, string $answer, string $keep = ''): array
    {
        $q = self::analyse($question, $keep);
        $a = self::analyse($answer, $keep, $q['names']);

        return ['question' => $q['text'], 'answer' => $a['text'], 'findings' => array_values(array_unique([...$q['findings'], ...$a['findings']]))];
    }

    /**
     * What personal data the text holds, as the admin reads it: "nomor HP: 0812 3456 7890".
     *
     * @return list<string>
     */
    public static function findings(string $text, string $keep = ''): array
    {
        return self::analyse($text, $keep)['findings'];
    }

    /**
     * True when the question is one buyer's order, form or address rather than a general question: it gave a
     * name, phone number, address, e-mail or account. Such a question is never kept as knowledge.
     */
    public static function isPersonalMessage(string $text, string $keep = ''): bool
    {
        $clean = self::scrub($text, $keep);

        foreach (self::NOT_GENERAL as $placeholder) {
            if (str_contains($clean, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The findings that stop a save: a city alone is replaced by "[kota]" as it is saved, the rest needs the admin.
     *
     * @param  list<string>  $findings
     * @return list<string>
     */
    public static function blocking(array $findings): array
    {
        return array_values(array_filter($findings, fn (string $f) => ! str_starts_with($f, self::LABELS[self::CITY].':')));
    }

    /** True when a (cleaned) question still holds a placeholder only one buyer's message needs. */
    public static function hasPersonalPlaceholder(string $text): bool
    {
        foreach (self::NOT_GENERAL as $placeholder) {
            if (str_contains($text, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The scan of old knowledge: what a buyer's data the entry holds. Entries learned from chats are checked for
     * everything; entries the agency wrote or uploaded only for a buyer's name, phone, e-mail or address in the
     * question, or a buyer introducing themselves to order ("saya ... mau order"), so the store's own address and
     * number in its notes are not flagged.
     *
     * @return list<string>
     */
    public static function inKnowledge(AiKnowledgeItem $item, string $keep = ''): array
    {
        if ($item->source === 'learned') {
            return array_values(array_unique([...self::findings((string) $item->question, $keep), ...self::findings((string) $item->content, $keep)]));
        }

        $found = array_values(array_filter(self::findings((string) $item->question, $keep), fn (string $f) => ! str_starts_with($f, self::LABELS[self::CITY])));

        foreach (self::introductions($item->title.' '.$item->content) as $name) {
            $found[] = self::LABELS[self::NAME].': '.$name;
        }

        return array_values(array_unique($found));
    }

    /**
     * What the agency wrote itself, inside the current tenant: its name and instructions, its own (not learned)
     * knowledge and its warehouse city. Their names, numbers and places are not a buyer's.
     */
    public static function keep(): string
    {
        try {
            $tenant = app(CurrentTenant::class)->get();
        } catch (Throwable) {
            return '';
        }

        $own = AiKnowledgeItem::query()->where('source', '!=', 'learned')->get(['title', 'question', 'content'])
            ->map(fn (AiKnowledgeItem $i) => $i->title.' '.$i->content)->implode("\n");

        return implode("\n", array_filter([$tenant->name, $tenant->ai_instructions, ShippingSettings::of($tenant)->origin(), $own]));
    }

    /**
     * Cities, regencies and provinces the text names, by their canonical lower-case names: the built-in list,
     * the imported regions, and any capitalised word after "Kota" or "Kab." ("Kab. Buol").
     *
     * @return list<string>
     */
    public static function places(string $text): array
    {
        return array_values(array_unique(self::placeMatches($text)));
    }

    /** @return array<string, string> each place as written (lower case) => its canonical name */
    private static function placeMatches(string $text): array
    {
        $found = [];
        $lower = mb_strtolower($text);

        if (preg_match_all('/(?<![\p{L}\p{N}])(kota\s+administrasi\s+|kabupaten\s+administrasi\s+|kota\s+|kab\.?\s*|kabupaten\s+)?('.self::placePattern().')(?![\p{L}\p{N}])/u', $lower, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = preg_replace('/\s+/', ' ', $match[2]);

                if ($match[1] === '' && in_array($name, self::COMMON_WORDS, true)) {
                    continue;
                }

                $found[$name] = self::placeIndex()[$name] ?? $name;
            }
        }

        if (preg_match_all('/(?<![\p{L}])(?i:kota|kab\.?|kabupaten)\s*(\p{Lu}\p{Ll}+)(?![\p{L}])/u', $text, $m)) {
            foreach ($m[1] as $name) {
                $name = mb_strtolower($name);

                if (! in_array(strtok($name, ' '), self::NOT_PLACE_AFTER, true)) {
                    $found[$name] = self::placeIndex()[$name] ?? $name;
                }
            }
        }

        return $found;
    }

    /** The provinces of the places named in a text, so "Kab. Buol, Sulawesi Tengah" is fine once Buol is known. */
    public static function provincesOf(array $places): array
    {
        $out = [];

        foreach ($places as $place) {
            if (($province = Regions::provinceOfCity($place)) !== null) {
                $out[] = mb_strtolower($province);
            }

            try {
                foreach (RegionDirectory::cityProvinces($place) as $province) {
                    $out[] = mb_strtolower($province);
                }
            } catch (Throwable) {
                // No regions table: the built-in list is all there is.
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The names a text greets or addresses someone by: "Kak Zainal", "Pak Budi Santoso", "atas nama Rina",
     * "saya zainal abidin mau order".
     *
     * @return list<string>
     */
    public static function names(string $text): array
    {
        $names = [];

        if (preg_match_all('/(?<![\p{L}])(?i:'.self::HONORIFICS.')\.?\s+(\p{Lu}[\p{Ll}\']+(?:\s+\p{Lu}[\p{Ll}\']+){0,2})/u', $text, $m)) {
            foreach ($m[1] as $name) {
                if (($name = self::trimName($name)) !== null) {
                    $names[] = $name;
                }
            }
        }

        if (preg_match_all('/(?<![\p{L}])(?i:atas\s+nama|a\.n\.?|nama\s+penerima|penerima)\s*:?\s*(\p{Lu}[\p{Ll}\']+(?:\s+\p{Lu}[\p{Ll}\']+){0,2})/u', $text, $m)) {
            foreach ($m[1] as $name) {
                if (($name = self::trimName($name)) !== null) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique([...$names, ...self::introductions($text)]));
    }

    /** True when the text holds this word or phrase on its own (not inside another word), ignoring case. */
    public static function mentions(string $haystack, string $needle): bool
    {
        $needle = trim(preg_replace('/\s+/', ' ', mb_strtolower($needle)));

        return $needle !== '' && preg_match('/(?<![\p{L}\p{N}])'.str_replace(' ', '\s+', preg_quote($needle, '/')).'(?![\p{L}\p{N}])/u', mb_strtolower($haystack)) === 1;
    }

    // ---- The rules ----------------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $names
     * @return array{text: string, findings: list<string>, names: list<string>}
     */
    private static function analyse(string $text, string $keep = '', array $names = []): array
    {
        $findings = [];
        $found = [];
        $kept = fn (string $match) => $keep !== '' && (self::mentions($keep, $match) || self::digitsIn($keep, $match));
        $replace = function (string $pattern, string $placeholder, int $group = 0, ?callable $accept = null) use (&$text, &$findings, $kept) {
            $text = preg_replace_callback($pattern, function (array $m) use ($placeholder, $group, $accept, &$findings, $kept) {
                $value = $m[$group] ?? '';

                if (trim($value) === '' || $kept($value) || ($accept && ! $accept($value, $m))) {
                    return $m[0];
                }

                $findings[] = self::LABELS[$placeholder].': '.trim($value);

                return $group === 0 ? $placeholder : str_replace($value, $placeholder, $m[0]);
            }, $text) ?? $text;
        };

        // E-mail, then phone numbers (08…, +62 8…, 62 8…), then bank accounts and other long numbers.
        $replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', self::EMAIL);
        $replace('/(?<![\d\w])(?:\+?62|0)[\s.\-]?8\d(?:[\s.\-]?\d){6,11}(?!\d)/u', self::PHONE);
        $replace('/(?<![\p{L}])(?:no\.?\s*)?(?:rek(?:ening)?|norek|no\.?\s*rek|account|acc)\b[^\d\n]{0,25}?(\d[\d\s.\-]{4,}\d)/iu', self::ACCOUNT, 1);
        $replace('/(?<![\d.,])\d{10,}(?![\d.,])/u', self::ACCOUNT);

        // Names: given in a form ("Nama: …"), introduced ("saya … mau order", "nama saya …"), or after "atas nama".
        $field = '/(?<![\p{L}])(?:nama(?:\s+(?:lengkap|penerima|pemesan|pembeli|saya|sy))?)\s*[:=]\s*([^\n,;]+)/iu';
        if (preg_match_all($field, $text, $m)) {
            array_push($names, ...array_filter(array_map(self::trimName(...), $m[1])));
        }
        $replace($field, self::NAME, 1);
        foreach (self::introductions($text) as $name) {
            $names[] = $name;
        }
        $replace('/(?<![\p{L}])(?:atas\s+nama|a\.n\.?|penerima)\s*:?\s+('.self::NAME_WORD.'(?:\s+'.self::NAME_WORD.'){0,2})/iu', self::NAME, 1,
            fn (string $v) => self::trimName($v) !== null);

        foreach (array_unique(array_filter($names)) as $name) {
            $replace('/(?<![\p{L}])'.str_replace('\ ', '\s+', preg_quote($name, '/')).'(?![\p{L}])/iu', self::NAME);
        }

        // A greeting with a capitalised name after it ("Baik kak Zainal").
        $text = preg_replace_callback('/(?<![\p{L}])(?i:'.self::HONORIFICS.')\.?\s+(\p{Lu}[\p{Ll}\']+(?:\s+\p{Lu}[\p{Ll}\']+){0,2})/u', function (array $m) use (&$findings, $kept) {
            $name = self::trimName($m[1]);

            if ($name === null || $kept($name) || ! str_starts_with($m[1], $name)) {
                return $m[0];
            }

            $findings[] = self::LABELS[self::NAME].': '.$name;

            return substr($m[0], 0, -strlen($m[1])).self::NAME.substr($m[1], strlen($name));
        }, $text) ?? $text;

        // Address: a whole "Alamat: …" line, then street, RT/RW, kelurahan, kecamatan and postal code anywhere.
        $replace('/(?<![\p{L}])(?:alamat(?:\s+(?:lengkap|kirim|pengiriman|rumah|penerima|tujuan))?)\s*[:=]\s*([^\n]+)/iu', self::ADDRESS, 1);
        $replace('/(?<![\p{L}])(?:jl|jln|gg|perum|perumahan|komplek|kompleks|komp|dusun|dsn|kp|blok)\.?\s+[^\n,;]+/iu', self::ADDRESS);
        $replace('/(?<![\p{L}])(?i:jalan)\s+(?:\p{Lu}|\d)[^\n,;]*/u', self::ADDRESS);
        $replace('/(?<![\p{L}])(?i:jalan)\s+raya\s+[^\n,;]+/u', self::ADDRESS);
        $replace('/(?<![\p{L}])(?:rt|rw)\.?\s*[:.]?\s*\d{1,3}(?:\s*[\/\\\\-]\s*(?:rw\.?\s*)?\d{1,3})?(?![\d])/iu', self::ADDRESS);
        $replace('/(?<![\p{L}])(?:kel|kelurahan|desa|ds|kec|kecamatan)\.?\s*:?\s+([\p{L}][^\n,;.]*)/iu', self::ADDRESS, 0,
            fn (string $v, array $m) => ! in_array(mb_strtolower(strtok(trim($m[1]), ' ')), self::NOT_PLACE_AFTER, true));
        $replace('/(?<![\p{L}])(?:kode\s*pos|kodepos)\s*:?\s*\d{5}(?!\d)/iu', self::ADDRESS);

        // Cities, regencies and provinces.
        foreach (array_keys(self::placeMatches($text)) as $place) {
            $replace('/(?<![\p{L}\p{N}])(?:(?i:kota\s+administrasi|kabupaten\s+administrasi|kota|kab\.?|kabupaten|provinsi|prov\.?)\s*)?'.str_replace('\ ', '\s+', preg_quote($place, '/')).'(?![\p{L}\p{N}])/iu', self::CITY);
        }

        // "[alamat], [kota], [kota] 95111" reads as one address.
        $text = preg_replace_callback('/\[(?:alamat|kota)\](?:[\s,.\-\/]*(?:\[(?:alamat|kota)\]|\d{5}(?!\d)))+/u',
            fn (array $m) => str_contains($m[0], self::ADDRESS) ? self::ADDRESS : self::CITY, $text) ?? $text;

        return ['text' => $text, 'findings' => array_values(array_unique($findings)), 'names' => array_values(array_unique(array_filter($names)))];
    }

    /**
     * The name a buyer introduces themselves with: "saya zainal abidin mau order", "nama saya Rina", "aku Budi dari
     * Medan". Lower case is fine here; the words before "mau / ingin / order / dari" are the name.
     *
     * @return list<string>
     */
    private static function introductions(string $text): array
    {
        $names = [];
        $patterns = [
            // "saya zainal abidin mau order": the words between "saya" and the order are the name.
            '/(?<![\p{L}])(?:saya|sy|aku)\s+(?:adalah\s+|bernama\s+)?((?:'.self::NAME_WORD.'\s+){0,3}?'.self::NAME_WORD.')\s+(?=(?:mau|ingin|pengen|pingin|mo|mw|order|pesan|beli|checkout)(?![\p{L}]))/iu',
            // "saya Rina dari Padang": a capitalised name before where they live.
            '/(?<![\p{L}])(?i:saya|sy|aku)\s+(\p{Lu}\p{Ll}+(?:\s+\p{Lu}\p{Ll}+){0,2})\s+(?=(?i:dari|di|tinggal)(?![\p{L}]))/u',
            // "nama saya Rina", "namaku Budi Santoso dari Medan".
            '/(?<![\p{L}])(?:nama\s+(?:saya|sy|aku)|namaku)\s+(?:adalah\s+|ialah\s+)?((?:'.self::NAME_WORD.'\s+){0,3}?'.self::NAME_WORD.')\s*(?=(?:mau|ingin|order|pesan|dari|di|tinggal|,|\.|\n|$)(?![\p{L}]))/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $m)) {
                foreach ($m[1] as $name) {
                    if (($name = self::trimName($name)) !== null) {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    /** The name without words that are not a name around it; null when nothing is left. */
    private static function trimName(string $name): ?string
    {
        $words = preg_split('/\s+/u', trim($name, " \t\n.,'")) ?: [];

        while ($words !== [] && in_array(mb_strtolower(rtrim($words[0], '.,')), self::NOT_NAMES, true)) {
            array_shift($words);
        }

        while ($words !== [] && in_array(mb_strtolower(rtrim(end($words), '.,')), self::NOT_NAMES, true)) {
            array_pop($words);
        }

        // Any function word left in the middle means this was a sentence, not a name.
        foreach ($words as $word) {
            if (in_array(mb_strtolower($word), self::NOT_NAMES, true) || mb_strlen($word) < 2 || preg_match('/\d/', $word)) {
                return null;
            }
        }

        return $words === [] ? null : implode(' ', $words);
    }

    /** True when the number in $match (8+ digits) also appears in $keep: the store's own number or account. */
    private static function digitsIn(string $keep, string $match): bool
    {
        $digits = preg_replace('/\D/', '', $match);

        if (strlen($digits) < 8) {
            return false;
        }

        // 0812…, 62812… and +62 812… are one number.
        $digits = preg_replace('/^(?:62|0)/', '', $digits);

        return str_contains(preg_replace('/\D/', '', $keep), $digits);
    }

    /** @return array<string, string> */
    private static function placeIndex(): array
    {
        $version = '0';

        try {
            $version = RegionDirectory::version();
        } catch (Throwable) {
        }

        // Kept for the request (the container), and rebuilt after a regions import.
        $key = 'personal-data.places.'.$version;

        if (app()->bound($key)) {
            return app($key);
        }

        $index = [];

        foreach (Regions::PROVINCES as $province => $aliases) {
            $index[mb_strtolower($province)] = mb_strtolower($province);

            foreach ($aliases as $alias) {
                if (mb_strlen($alias) >= 4) {
                    $index[$alias] = mb_strtolower($province);
                }
            }
        }

        foreach (Regions::CITIES as $cities) {
            foreach ($cities as $city) {
                $index[$city] = RegionDirectory::CITY_ALIASES[$city] ?? $city;
            }
        }

        foreach (RegionDirectory::CITY_ALIASES as $alias => $city) {
            if (mb_strlen($alias) >= 4) {
                $index[$alias] = $city;
            }
        }

        try {
            foreach (RegionDirectory::cityNames() as $name) {
                $city = Regions::city($name);

                if (mb_strlen($city) >= 3) {
                    $index[$city] ??= $city;
                }
            }
        } catch (Throwable) {
            // No regions table: the built-in list is enough.
        }

        app()->instance($key, $index);

        return $index;
    }

    private static function placePattern(): string
    {
        $key = 'personal-data.pattern.'.md5(implode('|', array_keys(self::placeIndex())));

        if (app()->bound($key)) {
            return app($key);
        }

        $names = array_keys(self::placeIndex());
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        app()->instance($key, $pattern = implode('|', array_map(fn ($n) => str_replace('\ ', '\s+', preg_quote($n, '/')), $names)));

        return $pattern;
    }
}
