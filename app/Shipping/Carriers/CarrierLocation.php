<?php

namespace App\Shipping\Carriers;

/** A place in the provider's own list (Everpro: a sub-district), which orders and rates refer to by id. */
final class CarrierLocation
{
    public function __construct(
        public readonly string $id,
        public readonly string $subDistrict,
        public readonly ?string $district,
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly ?string $postalCode,
    ) {}

    /** "Sekeloa, Coblong, Kota Bandung, Jawa Barat (40134)" */
    public function label(): string
    {
        return implode(', ', array_filter([$this->subDistrict, $this->district, $this->city, $this->province])).($this->postalCode ? ' ('.$this->postalCode.')' : '');
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self((string) $a['id'], (string) ($a['subDistrict'] ?? ''), $a['district'] ?? null, $a['city'] ?? null, $a['province'] ?? null, $a['postalCode'] ?? null);
    }
}
