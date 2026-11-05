<?php

namespace App\Shipping\Carriers;

/** What a provider's callback says: a tracking number was issued, or a parcel's status changed. */
final class CallbackEvent
{
    public function __construct(
        public readonly ?string $awb,
        public readonly ?string $clientOrderNo,
        public readonly ?string $code,
        public readonly ?string $status,
        public readonly ?string $reason = null,
        public readonly ?string $description = null,
    ) {}
}
