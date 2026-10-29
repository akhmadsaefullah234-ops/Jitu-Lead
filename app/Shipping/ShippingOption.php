<?php

namespace App\Shipping;

/** One courier's price for the parcel. */
final class ShippingOption
{
    public function __construct(
        public readonly string $courier,
        public readonly int $price,
        public readonly ?int $etaMin,
        public readonly ?int $etaMax,
    ) {}

    public function eta(): ?string
    {
        return match (true) {
            $this->etaMin === null && $this->etaMax === null => null,
            $this->etaMin === $this->etaMax || $this->etaMax === null => $this->etaMin.' hari',
            $this->etaMin === null => $this->etaMax.' hari',
            default => $this->etaMin.'–'.$this->etaMax.' hari',
        };
    }
}
