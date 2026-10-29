<?php

namespace App\Shipping;

/** Where a parcel goes and what is in it. */
final class ShippingRequest
{
    /** @param  list<ShippingItem>  $items */
    public function __construct(
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly array $items,
        public readonly ?string $district = null,
    ) {}
}
