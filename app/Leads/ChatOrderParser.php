<?php

namespace App\Leads;

use App\Ai\ReplySignals;

/**
 * Reads what a buyer wrote in chat for an order: a "format order" (Nama: …, Alamat: …, Warna: …, Jumlah: …),
 * a full address typed out, or a piece added later ("Rt1 rw 2", a landmark after being asked for one, a postal
 * code). Only text, no database: App\Leads\ChatShippingDetails merges the result into the lead.
 *
 * Address parts use the keys of App\LeadForms\FormFields::ADDRESS_PARTS (street, house, landmark, rt_rw, dusun,
 * kelurahan, kecamatan, kota, provinsi, kode_pos).
 */
class ChatOrderParser
{
    /** Label written before ":" => what it holds. */
    private const LABELS = [
        'name' => ['nama', 'nama lengkap', 'nama penerima', 'penerima', 'atas nama', 'an'],
        'phone' => ['no hp', 'hp', 'no wa', 'wa', 'whatsapp', 'no telp', 'telp', 'telepon', 'nomor hp', 'no hp/wa', 'hp/wa', 'nomor wa', 'kontak'],
        'address' => ['alamat', 'alamat lengkap', 'alamat kirim', 'alamat pengiriman', 'alamat rumah', 'alamat tujuan'],
        'rt_rw' => ['rt/rw', 'rt rw', 'rtrw'],
        'rt' => ['rt'],
        'rw' => ['rw'],
        'house' => ['no rumah', 'nomor rumah', 'no', 'nomor'],
        'landmark' => ['patokan', 'ancer ancer', 'ancer', 'ciri rumah', 'ciri ciri rumah', 'keterangan', 'ket', 'patokan rumah'],
        'dusun' => ['dusun', 'kampung', 'kp', 'dsn'],
        'kelurahan' => ['kel', 'kelurahan', 'desa', 'kelurahan/desa', 'desa/kelurahan', 'kel/desa', 'desa/kel', 'ds'],
        'kecamatan' => ['kec', 'kecamatan'],
        'kota' => ['kota', 'kab', 'kabupaten', 'kota/kab', 'kota/kabupaten', 'kab/kota', 'kabupaten/kota'],
        'provinsi' => ['prov', 'provinsi', 'propinsi'],
        'kode_pos' => ['kode pos', 'kodepos', 'kd pos', 'pos'],
        'variant' => ['warna', 'varian', 'variant', 'ukuran', 'size', 'model', 'tipe', 'type', 'motif', 'pilihan', 'warna/ukuran'],
        'quantity' => ['jumlah', 'qty', 'jml', 'jumlah pesanan', 'banyaknya', 'pcs', 'jumlah order'],
        'payment' => ['pembayaran', 'bayar', 'metode pembayaran', 'cara bayar', 'payment', 'metode bayar', 'sistem pembayaran'],
        'product' => ['produk', 'barang', 'pesanan', 'order', 'nama produk', 'nama barang', 'item', 'pesan'],
    ];

    /** Words of a reply that is not a landmark ("nanti saya kirim", "belum tau"). */
    private const NOT_A_PLACE = '/\b(?:saya|aku|sy|nanti|besok|sebentar|bentar|gimana|bagaimana|berapa|bisa|mau|tidak|gak|ga|nggak|enggak|belum|udah|sudah|makasih|terima|tau|tahu|lupa|kirim|kak|ok|oke)\b/iu';

    /** Words of a typed address; two of them (or one and a number) make a message an address. */
    private const ADDRESS_WORDS = '/\b(?:jl|jln|jalan|gg|gang|blok|perum|perumahan|komplek|komp|rt|rw|kel|kelurahan|desa|ds|kec|kecamatan|kab|kabupaten|kota|dusun|dsn|kp|kampung|no)\b\.?/iu';

    /**
     * @param  ?string  $asked  the last message sent to the buyer before this one: tells what a bare answer is
     * @return array{name?: string, address?: string, parts?: array<string, string>, variant?: string, quantity?: int, payment?: string, product?: string}
     */
    public function parse(string $text, ?string $asked = null): array
    {
        $text = trim(str_replace("\r", '', $text));

        if ($text === '' || mb_strlen($text) > 2000) {
            return [];
        }

        $labeled = $this->labeled($text);

        return $labeled !== [] ? $this->fromLabels($labeled) : $this->fromFreeText($text, (string) $asked);
    }

    /**
     * The parts written inside one address line: RT/RW, house number, kelurahan, kecamatan, kota, postal code,
     * landmark, and the street (what comes before them).
     *
     * @return array<string, string>
     */
    public function addressParts(string $address): array
    {
        $parts = [];
        $address = trim(preg_replace('/[ \t]+/u', ' ', $address));

        if (preg_match('/\bRT\s*\/\s*RW\.?\s*[:.]?\s*0*(\d{1,3})\s*[\/\-.]\s*0*(\d{1,3})\b/iu', $address, $m)
            || preg_match('/\bRT\.?\s*[:.]?\s*0*(\d{1,3})\s*(?:[\/,\-]\s*|\s+)(?:RW\.?\s*[:.]?\s*)?0*(\d{1,3})\b/iu', $address, $m)) {
            $parts['rt_rw'] = self::rtRw($m[1], $m[2]);
        } elseif (preg_match('/\bRT\.?\s*[:.]?\s*0*(\d{1,3})\b/iu', $address, $m)) {
            $parts['rt_rw'] = self::rtRw($m[1], null);
        } elseif (preg_match('/\bRW\.?\s*[:.]?\s*0*(\d{1,3})\b/iu', $address, $m)) {
            $parts['rt_rw'] = self::rtRw(null, $m[1]);
        }

        if (preg_match('/\b(?:no|nomor)\b\.?\s*(?:rumah\s*)?[:.]?\s*(\d{1,4}[a-z]?(?:\s*[\/\-]\s*\d{1,4}[a-z]?)?)\b(?!\s*\d)/iu', preg_replace('/\b(?:no|nomor)\.?\s*(?:hp|telp|wa|tlp)\b[^,\n]*/iu', '', $address), $m)) {
            $parts['house'] = strtoupper(preg_replace('/\s+/', '', $m[1]));
        }

        if (preg_match('/\b(?:kel(?:urahan)?|desa|ds)\b\.?\s*[:.]?\s*([^,\n]+?)(?=\s*(?:,|\n|\bkec(?:amatan)?\b|$))/iu', $address, $m)) {
            $parts['kelurahan'] = self::clean($m[1]);
        }

        if (preg_match('/\bkec(?:amatan)?\b\.?\s*[:.]?\s*([^,\n]+?)(?=\s*(?:,|\n|\b(?:kab(?:upaten)?|kota)\b|\d{5}|$))/iu', $address, $m)) {
            $parts['kecamatan'] = self::clean($m[1]);
        }

        if (preg_match('/\b(kota|kab(?:upaten)?)\b\.?\s+([^,\n\d]+?)(?=\s*(?:,|\n|\d{5}|$))/iu', $address, $m)) {
            $parts['kota'] = (mb_strtolower($m[1]) === 'kota' ? 'Kota ' : 'Kab. ').self::clean($m[2]);
        }

        if (preg_match('/(?<![\d+])(\d{5})(?!\d)/', preg_replace('/(?:\+?62|0)8\d{7,12}/', '', $address), $m)) {
            $parts['kode_pos'] = $m[1];
        }

        if (preg_match('/\b(?:patokan|ancer(?:\s*-?\s*ancer)?)\b\s*[:.]?\s*([^,\n)]+)/iu', $address, $m)) {
            $parts['landmark'] = self::clean($m[1]);
        } elseif (preg_match('/\(([^)]{3,80})\)/u', $address, $m)) {
            $parts['landmark'] = self::clean($m[1]);
        } elseif (preg_match('/\b((?:dekat|depan|samping|sebelah|belakang|seberang)\s+[^,\n]{3,60})/iu', $address, $m)) {
            $parts['landmark'] = self::clean($m[1]);
        }

        if (preg_match('/\b(?:dusun|dsn|kampung|kp)\b\.?\s+([^,\n]+?)(?=\s*(?:,|\n|\bRT\b|$))/iu', $address, $m)) {
            $parts['dusun'] = self::clean($m[1]);
        }

        // The street: the first part, without RT/RW and the number.
        $first = trim(explode(',', explode("\n", $address)[0])[0]);
        $street = trim(preg_replace([
            '/\bRT\s*\/?\s*RW\b.*$/iu', '/\bRT\b\.?\s*\d.*$/iu', '/\b(?:no|nomor)\b\.?\s*(?:rumah\s*)?\d.*$/iu', '/\((.*)\)/u',
        ], '', $first), " \t,.-");

        if (mb_strlen($street) >= 3 && ! preg_match('/^(?:kel(?:urahan)?|desa|kec(?:amatan)?|kota|kab(?:upaten)?)\b/iu', $street)) {
            $parts['street'] = $street;
        }

        return array_filter($parts, fn ($v) => $v !== '');
    }

    /** True when a message reads like an address: two address words, or one with a number. */
    public static function looksLikeAddress(string $text): bool
    {
        $words = preg_match_all(self::ADDRESS_WORDS, $text);

        return mb_strlen($text) >= 15 && ($words >= 2 || ($words === 1 && preg_match('/\d/', $text)));
    }

    /** @return array<string, string> label kind => value, in the order written; lines after "Alamat:" without a label go with it */
    private function labeled(string $text): array
    {
        $out = [];
        $current = null;
        $found = 0;

        foreach (preg_split('/\n+/u', $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^[-•*>\d.)\s]*([\p{L}][\p{L} .\/\-]{0,30}?)\s*[:=]\s*(.*)$/u', $line, $m) && ($kind = $this->kind($m[1])) !== null) {
                $found++;
                $current = $kind;
                $value = trim($m[2]);
                $out[$kind] = isset($out[$kind]) && $kind === 'address' ? $out[$kind].', '.$value : $value;

                continue;
            }

            if ($current === 'address') {
                $out['address'] = trim(($out['address'] ?? '').', '.$line, ', ');
            }
        }

        // One stray "Note: …" is not an order; a single "Alamat: …" is.
        return $found >= 2 || isset($out['address']) || isset($out['kelurahan']) || isset($out['kecamatan']) || isset($out['kode_pos'])
            ? array_filter($out, fn ($v) => $v !== '' && ! preg_match('/^[-.\s]*$/', $v)) : [];
    }

    private function kind(string $label): ?string
    {
        $label = trim(preg_replace(['/\./u', '/[\s\-]+/u'], ['', ' '], mb_strtolower($label)));

        foreach (self::LABELS as $kind => $names) {
            if (in_array($label, $names, true)) {
                return $kind;
            }
        }

        return null;
    }

    private function fromLabels(array $labeled): array
    {
        $out = [];
        $parts = [];

        if (isset($labeled['address'])) {
            $out['address'] = self::clean($labeled['address']);
            $parts = $this->addressParts($out['address']);
        }

        if (isset($labeled['rt_rw']) && preg_match('/0*(\d{1,3})\D+0*(\d{1,3})/', $labeled['rt_rw'], $m)) {
            $parts['rt_rw'] = self::rtRw($m[1], $m[2]);
        } elseif (isset($labeled['rt']) || isset($labeled['rw'])) {
            $rt = preg_match('/\d+/', $labeled['rt'] ?? '', $a) ? $a[0] : null;
            $rw = preg_match('/\d+/', $labeled['rw'] ?? '', $b) ? $b[0] : null;
            $parts['rt_rw'] = self::rtRw($rt, $rw);
        }

        foreach (['house', 'landmark', 'dusun', 'kelurahan', 'kecamatan', 'provinsi'] as $kind) {
            if (isset($labeled[$kind])) {
                $parts[$kind] = self::clean(preg_replace('/^(?:kel(?:urahan)?|desa|kec(?:amatan)?|prov(?:insi)?|dusun)\.?\s+/iu', '', $labeled[$kind]));
            }
        }

        if (isset($labeled['kota'])) {
            $parts['kota'] = self::clean($labeled['kota']);
        }

        if (isset($labeled['kode_pos']) && preg_match('/\d{5}/', $labeled['kode_pos'], $m)) {
            $parts['kode_pos'] = $m[0];
        }

        if (isset($labeled['name']) && mb_strlen($labeled['name']) >= 2 && ! preg_match('/\d{6,}/', $labeled['name'])) {
            $out['name'] = mb_substr(self::clean($labeled['name']), 0, 100);
        }

        if (isset($labeled['variant'])) {
            $out['variant'] = mb_substr(self::clean($labeled['variant']), 0, 100);
        }

        if (isset($labeled['quantity']) && preg_match('/\d+/', $labeled['quantity'], $m) && (int) $m[0] > 0 && (int) $m[0] <= 999) {
            $out['quantity'] = (int) $m[0];
        }

        if (isset($labeled['payment']) && ($payment = self::payment($labeled['payment'])) !== null) {
            $out['payment'] = $payment;
        }

        if (isset($labeled['product'])) {
            $out['product'] = mb_substr(self::clean($labeled['product']), 0, 150);

            if (! isset($out['quantity']) && preg_match('/(?:\bx\s*|\b)(\d{1,3})\s*(?:pcs|pc|buah|pasang|psg|biji|item|unit)?\s*$/iu', $labeled['product'], $m) && (int) $m[1] > 0) {
                $out['quantity'] = (int) $m[1];
            }
        }

        return array_filter($out + ['parts' => array_filter($parts, fn ($v) => $v !== '')], fn ($v) => $v !== [] && $v !== '');
    }

    private function fromFreeText(string $text, string $asked): array
    {
        if (ReplySignals::acknowledgement($text) || str_contains($text, '?')) {
            return [];
        }

        $short = mb_strlen($text) <= 80 && substr_count($text, "\n") <= 2;
        $askedFor = fn (string $pattern) => preg_match($pattern, $asked) === 1;

        // A whole address typed out (after being asked, or clearly one).
        $isAddress = self::looksLikeAddress($text) && (
            (preg_match_all(self::ADDRESS_WORDS, $text) >= 3 && mb_strlen($text) >= 25)
            || ($askedFor('/\balamat\b/iu') && preg_match('/\b(?:jl|jln|jalan|gg|gang|perum|perumahan|komplek|dusun|kp|kampung|desa)\b/iu', $text) === 1)
        );

        if ($isAddress) {
            return ['address' => self::clean($text), 'parts' => $this->addressParts($text)];
        }

        if (! $short) {
            return [];
        }

        $parts = array_intersect_key($this->addressParts($text), array_flip(['rt_rw', 'house', 'kelurahan', 'kecamatan', 'kota', 'kode_pos', 'landmark', 'dusun']));
        $rest = trim(preg_replace([
            '/\bRT\s*\/?\s*RW\.?\s*[:.]?\s*\d{1,3}\s*[\/\-.]\s*\d{1,3}\b/iu', '/\bRT\.?\s*[:.]?\s*\d{1,3}\b/iu', '/\bRW\.?\s*[:.]?\s*\d{1,3}\b/iu',
            '/\b(?:kak|ka|min|gan|sis|ya|kakak)\b\.?/iu', '/\b(?:no|nomor)\b\.?\s*(?:rumah\s*)?\d{1,4}[a-z]?\b/iu',
        ], '', $text), " \t\n,.-/");

        // A bare answer to "patokannya apa?": the landmark.
        if (! isset($parts['landmark']) && $rest !== '' && mb_strlen($rest) >= 3 && $askedFor('/\b(?:patokan|ancer|ciri\s*(?:-\s*ciri\s*)?rumah|dekat\s+apa)\b/iu') && ! preg_match('/^\d+$/', $rest)
            && ! preg_match(self::NOT_A_PLACE, $rest)) {
            $parts['landmark'] = self::clean($rest);
        }

        // A bare postal code, or a bare number after "nomor rumah?".
        if (! isset($parts['kode_pos']) && preg_match('/^\d{5}$/', $rest)) {
            $parts['kode_pos'] = $rest;
        } elseif (! isset($parts['house']) && preg_match('/^\d{1,4}[a-z]?$/iu', $rest) && $askedFor('/\b(?:no(?:mor)?\.?\s*rumah)/iu')) {
            $parts['house'] = strtoupper($rest);
        }

        // Only the parts this message really gives: RT/RW written out, or what was asked for.
        $parts = array_filter($parts, fn ($v, $k) => match ($k) {
            'rt_rw', 'kode_pos' => true,
            'landmark' => true,
            'house' => $askedFor('/\b(?:no(?:mor)?\.?\s*rumah)/iu') || preg_match('/\b(?:no|nomor)\b/iu', $text),
            default => preg_match('/\b(?:kel|kelurahan|desa|kec|kecamatan|kota|kab|kabupaten)\b/iu', $text) === 1,
        }, ARRAY_FILTER_USE_BOTH);

        $out = $parts === [] ? [] : ['parts' => $parts];

        if (mb_strlen($text) <= 40 && ($payment = self::payment($text)) !== null && $parts === []) {
            $out['payment'] = $payment;
        }

        return $out;
    }

    private static function payment(string $text): ?string
    {
        $cod = preg_match('/\bcod\b|bayar\s+di\s+tempat|\bbdt\b/iu', $text) === 1;
        $transfer = preg_match('/\b(?:transfer|tf|trf|tranfer|bank|bca|bri|bni|mandiri)\b/iu', $text) === 1;

        return ($cod xor $transfer) ? ($cod ? 'cod' : 'transfer') : null;
    }

    private static function rtRw(?string $rt, ?string $rw): string
    {
        $pad = fn (?string $n) => $n === null ? null : str_pad((string) (int) $n, 3, '0', STR_PAD_LEFT);

        return implode('/', array_filter([$pad($rt) ?? '000', $pad($rw)]));
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace(['/\s+/u', '/\s*,\s*(,\s*)+/u'], [' ', ', '], $value), " \t\n,.;-");
    }
}
