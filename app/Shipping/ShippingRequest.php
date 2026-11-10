<?php

namespace App\Shipping;

/**
 * Where a parcel goes, what is in it, and which of the shop's couriers to price (null: all of them). The postal
 * code and kelurahan, when known, let a courier API price the exact place (Everpro prices by postal code).
 */
final class ShippingRequest
{
    /**
     * @param  list<ShippingItem>  $items
     * @param  list<string>|null  $couriers
     */
    public function __construct(
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly array $items,
        public readonly ?string $district = null,
        public readonly ?array $couriers = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $subDistrict = null,
    ) {}
}
