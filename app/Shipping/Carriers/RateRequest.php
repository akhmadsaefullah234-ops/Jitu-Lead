<?php

namespace App\Shipping\Carriers;

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
    ) {}
}
