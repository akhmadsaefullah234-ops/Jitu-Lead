<?php

namespace App\Shipping\Carriers;

/** One courier service and what it costs. $meta: what the provider wants back when booking this rate (Everpro: category, signed_key, rate_name, cashback, durations). */
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

    /** The kind of service the provider grouped it under: regular, express, same_day, instant, cargo or vehicle. */
    public function category(): string
    {
        return (string) ($this->meta['category'] ?? 'regular');
    }

    /** Unique among one answer's rates: a rate code alone is not ("REG" is SiCepat's and others'). */
    public function key(): string
    {
        return $this->courier.'|'.$this->code;
    }

    public function cashback(): int
    {
        return (int) ($this->meta['cashback'] ?? 0);
    }

    /**
     * True for "JNE REG", "jne reg", "JNE Regular" or a rate code "JNE_REG". "JNE REG" also matches JNE's
     * rate named "Regular" whatever its code (REG23), since the code can change.
     */
    public function matches(?string $wanted): bool
    {
        $norm = fn (?string $v) => strtoupper(trim(preg_replace('/[\s_\-]+/', ' ', (string) $v)));
        $wanted = $norm($wanted);

        if ($wanted === '') {
            return false;
        }

        if (in_array($wanted, [$norm($this->name()), $norm($this->code), $norm($this->key()), $norm($this->courier.' '.($this->meta['rate_name'] ?? ''))], true)) {
            return true;
        }

        $courier = $norm($this->courier);

        if ($courier === '' || ! str_starts_with($wanted.' ', $courier.' ')) {
            return false;
        }

        $service = trim(substr($wanted, strlen($courier)));

        return in_array($service, ['REG', 'REGULAR'], true) && $this->category() === 'regular'
            && in_array($norm($this->service), ['REG', 'REGULAR'], true);
    }
}
