<?php

namespace App\Shipping;

use App\Support\PlainData;

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

    /**
     * Every constructor value as plain data, for a cache that keeps no objects (cache.serializable_classes is
     * false). fromData() builds the same option back; discount() and eta() follow from these values.
     *
     * @return array{courier: string, price: int, etaMin: ?int, etaMax: ?int, normalPrice: ?int, promo: ?string}
     */
    public function toData(): array
    {
        return [
            'courier' => $this->courier, 'price' => $this->price, 'etaMin' => $this->etaMin, 'etaMax' => $this->etaMax,
            'normalPrice' => $this->normalPrice, 'promo' => $this->promo,
        ];
    }

    /** @throws \InvalidArgumentException when a value is missing or of another type */
    public static function fromData(array $data): self
    {
        return new self(
            PlainData::string($data, 'courier'), PlainData::int($data, 'price'), PlainData::nullableInt($data, 'etaMin'),
            PlainData::nullableInt($data, 'etaMax'), PlainData::nullableInt($data, 'normalPrice'), PlainData::nullableString($data, 'promo'),
        );
    }

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
