<?php

namespace App\Shipping\Carriers;

/** What a price check is for. Places by the provider's id and by postal code (Everpro prices by postal code). */
final class RateRequest
{
    public function __construct(
        public readonly string $originId,
        public readonly string $destinationId,
        public readonly int $weightGrams,
        public readonly int $itemValue,
        public readonly bool $cod,
        public readonly bool $insurance = false,
        public readonly string $shipmentType = 'PICKUP',
        public readonly ?array $dimensions = null,
        public readonly ?string $originPostal = null,
        public readonly ?string $destinationPostal = null,
        public readonly ?string $originSubDistrict = null,
        public readonly ?string $destinationSubDistrict = null,
        public readonly ?int $codAmount = null,
    ) {}
}
