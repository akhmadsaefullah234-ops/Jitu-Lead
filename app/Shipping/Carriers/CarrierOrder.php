<?php

namespace App\Shipping\Carriers;

final class CarrierOrder
{
    public function __construct(
        public readonly string $clientOrderNo,
        public readonly ?string $orderId,
        public readonly ?string $awb = null,
        public readonly ?int $price = null,
        public readonly ?int $codAmount = null,
    ) {}
}
