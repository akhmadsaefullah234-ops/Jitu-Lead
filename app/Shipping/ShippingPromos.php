<?php

namespace App\Shipping;

/**
 * Shipping promos set per product (Produk > Iklan & promo): a discount off the courier's price, free shipping, or
 * a price the buyer pays at most, for all destinations, Java & Bali, outside Java & Bali, or chosen provinces,
 * from a minimum quantity. One promo per order: the one that takes the most off, never below zero. Applied to
 * every quote (App\Shipping\Shipping::quote), so the AI's answer, the recorded order and "Buat resi" all charge
 * the same.
 */
class ShippingPromos
{
    public const AREAS = [
        'all' => 'Semua tujuan',
        'java_bali' => 'Pulau Jawa & Bali',
        'outside_java_bali' => 'Luar Jawa & Bali',
        'provinces' => 'Provinsi tertentu',
    ];

    public const TYPES = [
        'discount' => 'Potongan ongkir (Rp)',
        'free' => 'Gratis ongkir',
        'cap' => 'Ongkir maksimal (Rp)',
    ];

    public const JAVA_BALI = ['DKI Jakarta', 'Jawa Barat', 'Banten', 'Jawa Tengah', 'DI Yogyakarta', 'Jawa Timur', 'Bali'];

    public static function apply(ShippingQuote $quote): ShippingQuote
    {
        if (! $quote->ok() || $quote->options === []) {
            return $quote;
        }

        $rules = self::matching($quote->items, $quote->province);

        if ($rules === []) {
            return $quote;
        }

        $options = array_map(function (ShippingOption $o) use ($rules) {
            [$off, $label] = self::pick($rules, $o->price);

            return $off === 0 ? $o : new ShippingOption($o->courier, $o->price - $off, $o->etaMin, $o->etaMax, $o->price, $label);
        }, $quote->options);

        return $quote->copy(['options' => $options]);
    }

    /**
     * The promo for a price the provider gave ("Buat resi" with Everpro's rate), when the order was never priced.
     *
     * @param  list<ShippingItem>  $items
     * @return array{0: int, 1: ?string} how much comes off, and the promo's name
     */
    public static function best(array $items, ?string $province, int $price): array
    {
        return self::pick(self::matching($items, $province), $price);
    }

    /** @return array{0: int, 1: ?string} the single rule that takes the most off this price */
    private static function pick(array $rules, int $price): array
    {
        [$off, $label] = [0, null];

        foreach ($rules as $rule) {
            if (($cut = self::cut($rule, $price)) > $off) {
                [$off, $label] = [$cut, $rule['label']];
            }
        }

        return [$off, $label];
    }

    /**
     * @param  list<ShippingItem>  $items
     * @return list<array{type: string, amount: int, label: string}> the promos of the items that apply to this destination
     */
    private static function matching(array $items, ?string $province): array
    {
        $province = Regions::province((string) $province) ?? $province;
        $quantities = [];

        foreach ($items as $item) {
            if ($item->product !== null) {
                $quantities[$item->product->getKey()] = ($quantities[$item->product->getKey()] ?? 0) + $item->quantity;
            }
        }

        $out = [];

        foreach ($items as $item) {
            foreach (self::rules($item->product?->shipping_promos) as $rule) {
                if (($quantities[$item->product->getKey()] ?? 0) >= $rule['min_quantity'] && self::covers($rule, $province)) {
                    $out[] = ['label' => $rule['label'] ?: self::describe($rule, $item->product->name)] + $rule;
                }
            }
        }

        return $out;
    }

    /**
     * The stored rules, cleaned: unknown areas or types and empty amounts are dropped.
     *
     * @return list<array{area: string, provinces: list<string>, type: string, amount: int, min_quantity: int, label: string}>
     */
    public static function rules(mixed $stored): array
    {
        $out = [];

        foreach (is_array($stored) ? $stored : [] as $rule) {
            $area = $rule['area'] ?? null;
            $type = $rule['type'] ?? null;
            $amount = max(0, (int) ($rule['amount'] ?? 0));

            if (! isset(self::AREAS[$area], self::TYPES[$type]) || ($type === 'discount' && $amount === 0) || ($area === 'provinces' && empty($rule['provinces']))) {
                continue;
            }

            $out[] = [
                'area' => $area, 'provinces' => array_values(array_map(fn ($p) => Regions::province((string) $p) ?? (string) $p, (array) ($rule['provinces'] ?? []))),
                'type' => $type, 'amount' => $amount, 'min_quantity' => max(1, (int) ($rule['min_quantity'] ?? 1)), 'label' => trim((string) ($rule['label'] ?? '')),
            ];
        }

        return $out;
    }

    private static function covers(array $rule, ?string $province): bool
    {
        $inJavaBali = in_array($province, self::JAVA_BALI, true);

        return match ($rule['area']) {
            'all' => true,
            'java_bali' => $inJavaBali,
            'outside_java_bali' => $province !== null && ! $inJavaBali,
            'provinces' => in_array($province, $rule['provinces'], true),
        };
    }

    /** How much the rule takes off this price. */
    private static function cut(array $rule, int $price): int
    {
        return match ($rule['type']) {
            'free' => $price,
            'discount' => min($rule['amount'], $price),
            'cap' => max(0, $price - $rule['amount']),
        };
    }

    /** "Potongan ongkir Rp 30.000 (Tas Badminton, luar Jawa & Bali)" */
    public static function describe(array $rule, string $product): string
    {
        $what = match ($rule['type']) {
            'free' => 'Gratis ongkir',
            'discount' => 'Potongan ongkir '.ShippingQuote::rupiah($rule['amount']),
            'cap' => 'Ongkir maks. '.ShippingQuote::rupiah($rule['amount']),
        };
        $where = match ($rule['area']) {
            'all' => null,
            'java_bali' => 'Jawa & Bali',
            'outside_java_bali' => 'luar Jawa & Bali',
            'provinces' => implode(', ', $rule['provinces']),
        };

        return $what.' ('.implode(', ', array_filter([$product, $where, $rule['min_quantity'] > 1 ? 'min. '.$rule['min_quantity'].' pcs' : null])).')';
    }
}
