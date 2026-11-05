<?php

namespace App\Shipping\Carriers;

/** Where a parcel is now. status is ours (ShipmentStatus), null when the provider's code is not known. */
final class TrackResult
{
    /** @param  list<TrackEvent>  $events */
    public function __construct(
        public readonly string $awb,
        public readonly ?string $status,
        public readonly ?string $code,
        public readonly ?string $reason = null,
        public readonly array $events = [],
        public readonly ?string $at = null,
    ) {}
}
