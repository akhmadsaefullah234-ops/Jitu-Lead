<?php

namespace App\Shipping;

/**
 * Prices a parcel from the agency's own zone table, with no courier API.
 *
 * The destination city picks the zone: a zone that lists the city wins, otherwise the zone that lists its
 * province (unless it excludes the city). The billable weight is the larger of the actual weight and the
 * volume weight (L x W x H / 6000), rounded up to whole kilograms, at least 1 kg. Each courier's price is
 * its per-kg rate in that zone times the billable weight. A zone marked "konfirmasi admin" has no price.
 */
class ZoneShippingRates implements ShippingProvider
{
    public function __construct(private ShippingSettings $settings) {}

    public function quote(ShippingRequest $request): ShippingQuote
    {
        $city = trim((string) $request->city);
        $province = Regions::province($request->province) ?? Regions::provinceOfCity($city);

        if ($city === '' || $province === null) {
            return new ShippingQuote(ShippingQuote::UNCLEAR_DESTINATION, $city === ''
                ? 'Kota atau kabupaten tujuan belum diketahui. Tanyakan kota/kabupaten dan kecamatannya.'
                : 'Provinsi untuk "'.$city.'" belum diketahui. Tanyakan provinsinya.', city: $city ?: null);
        }

        if ($request->items === []) {
            return new ShippingQuote(ShippingQuote::NOT_AVAILABLE, 'Produk dan jumlahnya belum diketahui.', city: $city, province: $province);
        }

        [$actual, $volume, $kg] = $this->weight($request->items);
        $zone = $this->zoneFor($city, $province);
        $base = fn (string $status, string $message, array $options = []) => new ShippingQuote(
            $status, $message, $options, $city, $province, $zone['name'] ?? null, $actual, $volume, $kg, $request->items,
        );

        if ($zone === null) {
            return $base(ShippingQuote::NEEDS_ADMIN, 'Belum ada tarif untuk tujuan ini. Ongkir perlu dikonfirmasi admin.');
        }

        $options = [];
        foreach ($this->settings->couriers() as $courier) {
            $rate = collect($zone['rates'])->first(fn (array $r) => mb_strtolower(trim($r['courier'])) === mb_strtolower($courier));

            if ($rate !== null) {
                $options[] = new ShippingOption($courier, (int) $rate['per_kg'] * $kg, $zone['eta_min'], $zone['eta_max']);
            }
        }

        if ($zone['confirm_admin'] || $options === []) {
            return $base(ShippingQuote::NEEDS_ADMIN, 'Ongkir ke zona '.$zone['name'].' perlu dikonfirmasi admin.');
        }

        return $base(ShippingQuote::OK, 'Ongkir dihitung dari tarif zona '.$zone['name'].'.', $options);
    }

    /**
     * @param  list<ShippingItem>  $items
     * @return array{0: int, 1: int, 2: int} actual grams, volume grams, billable kg
     */
    public function weight(array $items): array
    {
        $grams = 0;
        $cubic = 0;

        foreach ($items as $item) {
            $p = $item->product;
            $grams += ($p?->weight_grams ?: $this->settings->defaultWeightGrams()) * $item->quantity;

            if ($p && $p->length_cm && $p->width_cm && $p->height_cm) {
                $cubic += $p->length_cm * $p->width_cm * $p->height_cm * $item->quantity;
            }
        }

        $divisor = $this->settings->volumetricDivisor();
        $volumeGrams = intdiv($cubic * 1000 + $divisor - 1, $divisor);
        $kg = max(1, intdiv(max($grams, $volumeGrams) + 999, 1000));

        return [$grams, $volumeGrams, $kg];
    }

    private function zoneFor(string $city, string $province): ?array
    {
        $key = Regions::city($city);
        $zones = $this->settings->zones();
        $has = fn (array $list) => in_array($key, array_map(Regions::city(...), $list), true);

        foreach ($zones as $zone) {
            if ($has($zone['cities'])) {
                return $zone;
            }
        }

        foreach ($zones as $zone) {
            if (in_array($province, array_map(fn ($p) => Regions::province($p) ?? $p, $zone['provinces']), true) && ! $has($zone['excluded_cities'])) {
                return $zone;
            }
        }

        return null;
    }
}
