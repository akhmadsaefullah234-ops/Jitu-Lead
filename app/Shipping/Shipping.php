<?php

namespace App\Shipping;

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

        return new $class($settings);
    }

    /**
     * @param  list<array{product_id?: int|string|null, produk?: string|null, varian?: string|null, jumlah?: int|string|null}>  $items
     */
    public static function quote(Tenant $tenant, ?string $city, ?string $province, array $items, ?string $district = null): ShippingQuote
    {
        if (! self::available($tenant)) {
            return new ShippingQuote(ShippingQuote::NOT_AVAILABLE, 'Pengiriman belum diatur untuk agensi ini. Ongkir perlu dikonfirmasi admin.');
        }

        return self::provider($tenant)->quote(new ShippingRequest($city, $province, self::items($items), $district));
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
