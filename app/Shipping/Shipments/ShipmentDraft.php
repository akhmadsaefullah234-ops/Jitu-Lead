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
        public ?string $promo = null,
        public ?string $payment = null,
        public bool $unpaid = false,
        public int $goodsTotal = 0,
        public ?int $promoCharged = null,
        public ?int $recordedCharged = null,
        public int $calculatedCod = 0,
        public ?array $dimensions = null,
        public ?string $note = null,
        public bool $insurance = false,
        /** @var array<string, array{0: mixed, 1: mixed}> what the agent changed: field label => [calculated, used] */
        public array $edits = [],
    ) {}

    /** "Ongkir tercatat Rp45.000 berbeda dari hitungan promo Rp32.900 ..." when the lead's old shipping cost is not what the promo gives now. */
    public function warnings(): array
    {
        $rp = fn (?int $v) => 'Rp'.number_format((int) $v, 0, ',', '.');
        $out = [];

        if ($this->rate !== null && $this->recordedCharged !== null && $this->promoCharged !== null && $this->recordedCharged !== $this->promoCharged && $this->charged === $this->recordedCharged) {
            $out[] = 'Ongkir tercatat '.$rp($this->recordedCharged).' berbeda dari hitungan promo '.$rp($this->promoCharged).' — pastikan sesuai kesepakatan.';
        }

        if ($this->rate !== null && $this->cod && $this->codAmount !== $this->calculatedCod) {
            $out[] = 'Total COD '.$rp($this->codAmount).' berbeda dari hitungan '.$rp($this->calculatedCod).' (harga × jumlah + ongkir ditagih).';
        }

        return $out;
    }

    /**
     * The rates the agent picks from: regular and express, cheapest first; cargo too for a parcel over 5 kg. The
     * chosen rate is always listed.
     *
     * @return array<string, string> rate key => "JNE REG · Rp15.000 · cashback Rp3.150 · 3–6 hari"
     */
    public function choices(): array
    {
        $rp = fn (int $v) => 'Rp'.number_format($v, 0, ',', '.');
        $groups = $this->weightGrams > 5000 ? ['regular', 'express', 'cargo'] : ['regular', 'express'];
        $rates = array_filter($this->rates, fn (CarrierRate $r) => in_array($r->category(), $groups, true) || ($this->rate !== null && $r->key() === $this->rate->key()));
        usort($rates, fn (CarrierRate $a, CarrierRate $b) => $a->price <=> $b->price);
        $out = [];

        foreach ($rates as $r) {
            $out[$r->key()] = implode(' · ', array_filter([
                $r->name().($r->category() !== 'regular' ? ' ('.str_replace('_', ' ', $r->category()).')' : ''),
                $rp($r->price),
                $r->cashback() > 0 ? 'cashback '.$rp($r->cashback()) : null,
                $r->etaMax ? ($r->etaMin && $r->etaMin !== $r->etaMax ? $r->etaMin.'–' : '').$r->etaMax.' hari' : null,
            ]));
        }

        return $out;
    }

    public function ok(): bool
    {
        return $this->problems === [] && $this->destination !== null && $this->rate !== null;
    }

    public function summary(): string
    {
        $rp = fn (?int $v) => $v === null ? '-' : 'Rp'.number_format($v, 0, ',', '.');
        $lines = [];

        if ($this->rate) {
            $eta = $this->rate->etaMax ? ' · '.($this->rate->etaMin && $this->rate->etaMin !== $this->rate->etaMax ? $this->rate->etaMin.'–' : '').$this->rate->etaMax.' hari' : '';
            $lines[] = 'Kurir: '.$this->rate->name().' ('.($this->shipmentType === 'DROP' ? 'antar ke agen' : 'dijemput kurir').')'.$eta;
            $lines[] = 'Ongkir asli Everpro: '.$rp($this->rate->price).($this->rate->cashback() > 0 ? ' · cashback Everpro '.$rp($this->rate->cashback()) : '');
            $subsidy = $this->charged !== null ? $this->rate->price - $this->charged : 0;
            $lines[] = 'Ongkir ditagih ke pembeli: '.$rp($this->charged).match (true) {
                $subsidy > 0 => ' (potongan/subsidi penjual '.$rp($subsidy).($this->promo ? ', '.$this->promo : '').')',
                $subsidy < 0 => ' (lebih '.$rp(-$subsidy).' dari ongkir asli)',
                default => '',
            };
        }

        $kg = rtrim(rtrim(number_format($this->weightGrams / 1000, 2, ',', '.'), '0'), ',');
        $lines[] = 'Isi paket: '.($this->description ?: '-').' · nilai barang '.$rp($this->itemValue).' · berat '.$kg.' kg'
            .($this->dimensions ? ' · '.$this->dimensions['length'].'×'.$this->dimensions['width'].'×'.$this->dimensions['height'].' cm' : '')
            .($this->insurance ? ' · diasuransikan' : '');

        if (filled($this->note)) {
            $lines[] = 'Catatan untuk kurir: '.$this->note;
        }
        $lines[] = match (true) {
            $this->cod => 'Total COD (ditagih kurir ke pembeli): '.$rp($this->codAmount).($this->rate?->codFee ? ' (termasuk biaya COD '.$rp($this->rate->codFee).')' : ''),
            $this->unpaid => 'Bukan COD: pembeli bayar di muka. ⚠ Pembayaran transfer belum dikonfirmasi.',
            default => 'Bukan COD: pembeli sudah bayar di muka.',
        };

        if ($this->destination) {
            $lines[] = 'Tujuan: '.$this->destination->label();
        }

        if ($this->pickupTimes !== [] && $this->shipmentType === 'PICKUP') {
            $lines[] = 'Jemput paling cepat: '.Carbon::parse($this->pickupTimes[0])->timezone('Asia/Jakarta')->format('d M H:i');
        }

        return implode("\n", $lines);
    }
}
