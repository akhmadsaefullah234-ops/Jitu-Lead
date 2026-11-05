<?php

namespace App\Shipping\Shipments;

use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\CarrierRate;
use Illuminate\Support\Carbon;

/** What "Buat resi" is about to book, shown to the agent before anything is sent (App\Shipping\Shipments\Shipments::draft). */
final class ShipmentDraft
{
    /**
     * @param  list<string>  $problems  why it cannot be booked yet
     * @param  list<CarrierLocation>  $candidates  places the agent picks from when the address is not certain
     * @param  list<array{name: string, quantity: int, price: int}>  $items
     * @param  list<CarrierRate>  $rates
     * @param  list<string>  $pickupTimes
     */
    public function __construct(
        public array $problems = [],
        public ?CarrierLocation $destination = null,
        public array $candidates = [],
        public array $items = [],
        public int $itemValue = 0,
        public int $weightGrams = 0,
        public array $rates = [],
        public ?CarrierRate $rate = null,
        public bool $cod = false,
        public ?int $charged = null,
        public int $codAmount = 0,
        public array $pickupTimes = [],
        public string $description = '',
        public string $shipmentType = 'PICKUP',
    ) {}

    public function ok(): bool
    {
        return $this->problems === [] && $this->destination !== null && $this->rate !== null;
    }

    public function summary(): string
    {
        $rp = fn (?int $v) => $v === null ? '-' : 'Rp'.number_format($v, 0, ',', '.');
        $lines = [];

        if ($this->rate) {
            $lines[] = 'Kurir: '.$this->rate->name().' ('.($this->shipmentType === 'DROP' ? 'antar ke agen' : 'dijemput kurir').')';
            $lines[] = 'Ongkir Everpro: '.$rp($this->rate->price).' · ditagih ke pembeli: '.$rp($this->charged)
                .($this->charged !== null && $this->charged !== $this->rate->price ? ' (selisih '.$rp($this->rate->price - $this->charged).')' : '');
        }

        $lines[] = 'Isi paket: '.($this->description ?: '-').' · nilai barang '.$rp($this->itemValue).' · berat '.number_format($this->weightGrams / 1000, 0).' kg';
        $lines[] = $this->cod ? 'COD: kurir menagih '.$rp($this->codAmount).' ke pembeli'.($this->rate?->codFee ? ' (termasuk biaya COD '.$rp($this->rate->codFee).')' : '') : 'Bukan COD (transfer)';

        if ($this->destination) {
            $lines[] = 'Tujuan: '.$this->destination->label();
        }

        if ($this->pickupTimes !== [] && $this->shipmentType === 'PICKUP') {
            $lines[] = 'Jemput paling cepat: '.Carbon::parse($this->pickupTimes[0])->timezone('Asia/Jakarta')->format('d M H:i');
        }

        return implode("\n", $lines);
    }
}
