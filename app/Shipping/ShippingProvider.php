<?php

namespace App\Shipping;

/**
 * Something that can price a parcel: the agency's own zone table today, a courier API later. The AI tools and
 * the inbox only talk to this, so adding a provider changes neither.
 */
interface ShippingProvider
{
    public function quote(ShippingRequest $request): ShippingQuote;
}
