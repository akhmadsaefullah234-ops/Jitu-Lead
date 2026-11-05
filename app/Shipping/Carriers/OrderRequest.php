<?php

namespace App\Shipping\Carriers;

/** Everything a provider needs to book one parcel. Amounts in rupiah, weight in grams. */
final class OrderRequest
{
    /**
     * @param  array{name: string, phone: string, address: string, location_id: string, postal_code: ?string, email?: ?string}  $shipper
     * @param  array{name: string, phone: string, address: string, location_id: string, postal_code: ?string, note?: ?string}  $receiver
     */
    public function __construct(
        public readonly string $clientOrderNo,
        public readonly string $rateCode,
        public readonly array $shipper,
        public readonly array $receiver,
        public readonly int $weightGrams,
        public readonly string $description,
        public readonly int $itemValue,
        public readonly bool $cod,
        public readonly int $codAmount,
        public readonly int $shippingPrice,
        public readonly bool $insurance,
        public readonly string $shipmentType,
        public readonly ?string $pickupTime,
        public readonly ?array $dimensions = null,
    ) {}
}
