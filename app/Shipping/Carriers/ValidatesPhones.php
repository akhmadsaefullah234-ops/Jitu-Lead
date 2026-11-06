<?php

namespace App\Shipping\Carriers;

/**
 * A provider with its own rule for phone numbers: checked before "Buat resi" can book, so a number it would refuse
 * is told to the agent in the modal without calling the provider.
 */
interface ValidatesPhones
{
    /** @return list<string> e.g. "Nomor HP penerima tidak valid …" (empty: both numbers are fine) */
    public function phoneProblems(?string $shipperPhone, ?string $receiverPhone): array;
}
