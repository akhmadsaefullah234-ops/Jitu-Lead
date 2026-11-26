<?php

namespace App\Shipping;

use App\Ai\LeadQuote;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use InvalidArgumentException;

/**
 * The one door to shipping prices for the AI tools and the inbox: picks the agency's provider and turns loose
 * input (product ids or names, a city typed by a buyer) into a ShippingRequest.
 */
class Shipping
{
    public static function available(?Tenant $tenant): bool
    {
        return $tenant !== null && $tenant->industry()->key === 'retail' && ShippingSettings::of($tenant)->enabled();
    }

    public static function provider(Tenant $tenant): ShippingProvider
    {
        $settings = ShippingSettings::of($tenant);
        $class = config('shipping.providers.'.$settings->provider()) ?? throw new InvalidArgumentException('Unknown shipping provider '.$settings->provider());

        return app($class, ['settings' => $settings, 'tenant' => $tenant]);
    }

    /**
     * Prices the shop's primary courier, or the couriers asked for ($courier: a name such as "SiCepat REG" or
     * "sicepat", CourierChoice::ALL, or a list). A courier the shop does not use is never priced: the quote asks
     * for an admin. The postal code and kelurahan, when known, let Everpro price the exact place.
     *
     * A zone the shop marked "konfirmasi admin" is priced from Everpro when the shop has an Everpro account and
     * $lead (the chat's lead) has its address matched to Everpro's place for certain
     * (EverproShippingRates::forAdminZone). Otherwise it still asks for an admin, as before.
     *
     * @param  list<array{product_id?: int|string|null, produk?: string|null, varian?: string|null, jumlah?: int|string|null}>  $items
     * @param  list<string>|string|null  $courier
     */
    public static function quote(Tenant $tenant, ?string $city, ?string $province, array $items, ?string $district = null,
        array|string|null $courier = null, ?string $postalCode = null, ?string $subDistrict = null, ?Lead $lead = null): ShippingQuote
    {
        if (! self::available($tenant)) {
            return new ShippingQuote(ShippingQuote::NOT_AVAILABLE, 'Pengiriman belum diatur untuk agensi ini. Ongkir perlu dikonfirmasi admin.');
        }

        $settings = ShippingSettings::of($tenant);
        [$couriers, $unserved] = CourierChoice::resolve($settings, $courier);

        if ($unserved !== []) {
            return new ShippingQuote(ShippingQuote::NEEDS_ADMIN, 'Kurir '.implode(', ', $unserved).' tidak tersedia di toko ini (kurir toko: '.implode(', ', $settings->couriers()).'). Ongkir perlu dikonfirmasi admin.');
        }

        $postalCode = preg_match('/^\d{5}$/', $code = preg_replace('/\D/', '', (string) $postalCode)) ? $code : null;
        $subDistrict = filled($subDistrict) ? trim($subDistrict) : null;
        $request = new ShippingRequest($city, $province, self::items($items), $district, $couriers, $postalCode, $subDistrict);
        $quote = $settings->everproStrict() ? (new EverproShippingRates($settings, $tenant))->strict($request, $lead) : self::provider($tenant)->quote($request);

        if ($quote->status === ShippingQuote::NEEDS_ADMIN && (new ZoneShippingRates($settings))->confirmsAdmin($quote->zone)) {
            $quote = (new EverproShippingRates($settings, $tenant))->forAdminZone($request, $quote, $lead) ?? $quote;
        }

        if ($quote->ok() && (is_array($courier) ? $courier === [] : blank($courier))) {
            $primary = $settings->primaryCourier();
            // "Hanya Everpro" may price another shop courier when the primary one does not serve the place.
            $quote = $quote->copy(['primaryCourier' => $settings->everproStrict() && $primary !== null && $quote->option($primary) === null ? $quote->options[0]->courier : $primary]);
        }

        if ($settings->everproStrict() && ($kept = self::keptCost($settings, $quote, $lead, $courier)) !== null) {
            return $kept;
        }

        // The product's shipping promo (Produk > Iklan & promo) is taken off here, for every caller.
        return ShippingPromos::apply($quote);
    }

    /**
     * "Hanya Everpro": the lead's own order keeps the shipping cost it already has, for its own city and courier. A
     * cost CS typed (shipping_cost_source null) always wins over a new price; a cost the buyer already agreed to is
     * kept when Everpro cannot price it now, so the order is never blocked or changed behind the buyer's back.
     * Kept as is: a promo was already taken off it.
     *
     * @param  list<string>|string|null  $courier
     */
    private static function keptCost(ShippingSettings $settings, ShippingQuote $quote, ?Lead $lead, array|string|null $courier): ?ShippingQuote
    {
        if ($lead === null || $lead->shipping_cost === null || $quote->items === [] || ! LeadQuote::isOwnCity($lead, $quote->city)) {
            return null;
        }

        $manual = $lead->shipping_cost_source === null;

        if (! $manual && $quote->ok()) {
            return null;
        }

        $owned = $lead->courier ?: $settings->primaryCourier();
        $asked = is_array($courier) ? ($courier === [] ? null : $courier) : (blank($courier) || $courier === CourierChoice::ALL ? null : [$courier]);

        if ($owned === null || ($asked !== null && ! in_array(mb_strtolower($owned), array_map(fn ($c) => mb_strtolower(trim((string) $c)), $asked), true))) {
            return null;
        }

        return $quote->copy([
            'status' => ShippingQuote::OK, 'options' => [new ShippingOption($owned, (int) $lead->shipping_cost, null, null)],
            'message' => $manual ? 'Ongkir dari isian CS di data lead.' : 'Ongkir yang sudah disepakati pembeli (Everpro belum bisa menghitung ulang sekarang).',
            'zone' => null, 'source' => $manual ? ShippingSettings::MANUAL : (string) $lead->shipping_cost_source,
            'sourceNote' => $manual ? null : 'ongkir yang sudah disepakati', 'primaryCourier' => $asked === null ? $owned : null, 'adminReason' => null,
        ]);
    }

    /** Input items with a product that exists in this agency's catalogue (by id, else by name). Must run inside the tenant. */
    public static function items(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $id = $item['product_id'] ?? null;
            $name = trim((string) ($item['produk'] ?? ''));
            $product = filled($id) ? Property::query()->find((int) $id) : null;
            $product ??= $name !== '' ? (Property::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
                ?? Property::query()->whereRaw('lower(name) like ?', ['%'.addcslashes(mb_strtolower($name), '%_\\').'%'])->orderBy('id')->first()) : null;
            $quantity = max(1, min(1000, (int) ($item['jumlah'] ?? 1)));

            if ($product !== null || $name !== '') {
                $out[] = new ShippingItem($product, $product?->name ?? $name, filled($item['varian'] ?? null) ? trim((string) $item['varian']) : null, $quantity);
            }
        }

        return $out;
    }
}
