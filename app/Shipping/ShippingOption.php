<?php

namespace App\Shipping;

/** One courier's price for the parcel: what the buyer pays, and before a shipping promo what it would have been. */
final class ShippingOption
{
    public function __construct(
        public readonly string $courier,
        public readonly int $price,
        public readonly ?int $etaMin,
        public readonly ?int $etaMax,
        public readonly ?int $normalPrice = null,
        public readonly ?string $promo = null,
    ) {}

    /** What the promo takes off, 0 without one. */
    public function discount(): int
    {
        return $this->normalPrice !== null ? max(0, $this->normalPrice - $this->price) : 0;
    }

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
