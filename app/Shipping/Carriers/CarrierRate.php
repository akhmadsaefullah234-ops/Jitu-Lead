<?php

namespace App\Shipping\Carriers;

/** One courier service and what it costs. $meta: what the provider wants back when booking this rate (Everpro: signed_key, rateID, cashback, durations). */
final class CarrierRate
{
    public function __construct(
        public readonly string $code,
        public readonly string $courier,
        public readonly string $service,
        public readonly int $price,
        public readonly int $codFee = 0,
        public readonly int $insuranceFee = 0,
        public readonly ?int $etaMin = null,
        public readonly ?int $etaMax = null,
        public readonly array $meta = [],
    ) {}

    /** "JNE REG", the way couriers are named everywhere else in the app. */
    public function name(): string
    {
        return trim($this->courier.' '.$this->service);
    }

    /** True for "JNE REG", "jne reg" or a rate code "JNE_REG". */
    public function matches(?string $wanted): bool
    {
        $norm = fn (?string $v) => strtoupper(preg_replace('/[\s_\-]+/', ' ', trim((string) $v)));

        return $wanted !== null && ($norm($wanted) === $norm($this->name()) || $norm($wanted) === $norm($this->code));
    }
}
