<?php

namespace App\Shipping\Carriers;

/** One step of a parcel's journey, as the courier wrote it. */
final class TrackEvent
{
    public function __construct(
        public readonly string $at,
        public readonly ?string $code,
        public readonly ?string $status,
        public readonly string $description,
    ) {}

    public function toArray(): array
    {
        return ['at' => $this->at, 'code' => $this->code, 'status' => $this->status, 'description' => $this->description];
    }
}
