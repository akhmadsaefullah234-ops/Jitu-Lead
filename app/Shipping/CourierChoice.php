<?php

namespace App\Shipping;

/**
 * Which couriers a quote is for. The shop's primary courier (Pengaturan > Pengiriman > Kurir utama) is the only one
 * priced unless the buyer names another expedition ("SiCepat bisa?", "pakai J&T") or asks for others ("ada kurir
 * lain?"). A courier the shop does not use is never priced: a person answers that buyer.
 */
final class CourierChoice
{
    /** "Every courier of the shop": what the AI passes when the buyer asks for other couriers. */
    public const ALL = 'semua';

    /** Courier brands and how buyers write them (whole words, lower case). */
    private const BRANDS = [
        'JNE' => ['jne'],
        'J&T' => ['j&t', 'j & t', 'jnt', 'j n t', 'jnt express', 'j&t express'],
        'SiCepat' => ['sicepat', 'si cepat'],
        'AnterAja' => ['anteraja', 'anter aja'],
        'Ninja' => ['ninja', 'ninja xpress', 'ninja express'],
        'POS' => ['pos indonesia', 'kantor pos', 'pos aja'],
        'TIKI' => ['tiki'],
        'Wahana' => ['wahana'],
        'Lion Parcel' => ['lion parcel', 'lion'],
        'ID Express' => ['id express', 'idexpress'],
        'SAP' => ['sap express'],
        'Shopee Express' => ['shopee express', 'spx'],
        'GoSend' => ['gosend', 'go send', 'gojek'],
        'GrabExpress' => ['grab', 'grabexpress', 'grab express'],
        'Paxel' => ['paxel'],
        'Lalamove' => ['lalamove'],
        'RPX' => ['rpx'],
        'Indah Cargo' => ['indah cargo'],
        'Sentral Cargo' => ['sentral cargo'],
    ];

    /** "Ada kurir lain?", "ekspedisi apa aja?", "selain JNE bisa?", "pilihan kurirnya apa": every courier of the shop. */
    private const OTHERS = '/\b(?:(?:kurir|ekspedisi|expedisi|jasa\s+kirim|pengiriman)\w*\s+(?:yang\s+)?(?:lain\w*|apa\s+(?:aja|saja)|mana\s+(?:aja|saja)|lainnya)|(?:pilihan|opsi)\s+(?:kurir|ekspedisi)\w*|selain\s+(?:jne|kurir|ekspedisi)\w*|bisa\s+(?:pakai|pake|lewat|via)\s+(?:kurir|ekspedisi)\s+(?:lain|apa))\b/iu';

    /**
     * What the buyer asked for in a message: null (nothing: the primary courier), ALL, or the brands they named.
     *
     * @return list<string>|string|null
     */
    public static function fromText(?string $text): array|string|null
    {
        $text = mb_strtolower((string) $text);

        if (trim($text) === '') {
            return null;
        }

        $named = self::brands($text);

        if ($named !== []) {
            return $named;
        }

        return preg_match(self::OTHERS, $text) === 1 ? self::ALL : null;
    }

    /** @return list<string> the courier brands written in a text, in the order of BRANDS */
    public static function brands(?string $text): array
    {
        $text = mb_strtolower((string) $text);
        $found = [];

        foreach (self::BRANDS as $brand => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u', $text) === 1) {
                    $found[] = $brand;
                    break;
                }
            }
        }

        return $found;
    }

    /** "JNE" for "JNE REG", "SiCepat" for "sicepat reg"; null when no brand is written in it. */
    public static function brand(?string $courier): ?string
    {
        return self::brands($courier)[0] ?? null;
    }

    /**
     * The shop's couriers for what was asked: the primary courier for nothing, every courier for ALL, else the
     * shop's couriers of each name or brand asked. Asked names the shop does not use are returned as unserved.
     *
     * @param  list<string>|string|null  $wanted  a courier name ("SiCepat REG"), a brand ("sicepat"), ALL, a list of them, or null
     * @return array{0: list<string>, 1: list<string>} the shop's couriers to price, and the names asked that it does not serve
     */
    public static function resolve(ShippingSettings $settings, array|string|null $wanted): array
    {
        $couriers = $settings->couriers();
        $wanted = array_values(array_filter(array_map(fn ($w) => trim((string) $w), is_array($wanted) ? $wanted : [$wanted]), fn ($w) => $w !== ''));

        if ($wanted === []) {
            return [array_values(array_filter([$settings->primaryCourier()])), []];
        }

        $norm = fn (string $v) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));

        if (collect($wanted)->contains(fn ($w) => in_array($norm($w), [self::ALL, 'all', 'semua kurir', 'lain', 'kurir lain'], true))) {
            return [$couriers, []];
        }

        $picked = [];
        $unserved = [];

        foreach ($wanted as $w) {
            $key = $norm($w);
            $brand = self::brand($w);
            $match = array_values(array_filter($couriers, fn (string $c) => $norm($c) === $key || str_starts_with($norm($c).' ', $key.' ')
                || ($brand !== null && self::brand($c) === $brand)));

            if ($match === []) {
                $unserved[] = $brand ?? $w;
            }

            array_push($picked, ...$match);
        }

        // The primary courier first, then the shop's own order
        $primary = $settings->primaryCourier();
        $ordered = array_values(array_filter([$primary, ...array_filter($couriers, fn ($c) => $c !== $primary)], fn ($c) => $c !== null && in_array($c, $picked, true)));

        return [$ordered, array_values(array_unique($unserved))];
    }
}
