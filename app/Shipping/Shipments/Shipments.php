<?php

namespace App\Shipping\Shipments;

use App\Automation\StageAutomation;
use App\Enums\StageType;
use App\Jobs\CreateShipment;
use App\Jobs\SaveShipmentLabel;
use App\Leads\ChatShippingDetails;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\User;
use App\Shipping\Carriers\CallbackEvent;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\CarrierProvider;
use App\Shipping\Carriers\CarrierRate;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\OrderRequest;
use App\Shipping\Carriers\RateRequest;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Carriers\TrackEvent;
use App\Shipping\Carriers\TrackResult;
use App\Shipping\Carriers\ValidatesPhones;
use App\Shipping\ShippingItem;
use App\Shipping\ShippingPromos;
use App\Shipping\ShippingSettings;
use App\Shipping\ZoneShippingRates;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Booking and following parcels for a shop's leads, on whichever provider the agency picked
 * (App\Shipping\Carriers\Carriers). Must run inside the tenant.
 *
 * - draft(): checks the order (name, number, full address, products and quantity), maps the address to the
 *   provider's place list, weighs it (the catalogue weights, rounded up per kg), prices it and works out what
 *   the courier collects on delivery (COD: items + the shipping charged to the buyer + the provider's COD fee).
 * - book(): one row per order with a stable number "JL-{tenant}-{lead}-{n}"; a double click or retry finds
 *   the same row. The calls themselves run on the queue (App\Jobs\CreateShipment).
 * - place(): order, then tracking number; awbIssued() hands the number to the existing tracking flow
 *   (StageAutomation::shipped: the buyer gets it on WhatsApp once, the lead moves to Dikemas & dikirim).
 * - apply(): a status from a callback or tracking; earlier statuses that arrive late change nothing.
 */
class Shipments
{
    public function __construct(private CurrentTenant $current, private StatusNotices $notices) {}

    public function account(): ?CarrierAccount
    {
        return Carriers::configured($this->current->get()) ? Carriers::account($this->current->get()) : null;
    }

    public function provider(): CarrierProvider
    {
        return Carriers::for($this->current->get()) ?? throw new CarrierException('Pengiriman otomatis belum diatur di Pengaturan > Pengiriman.', CarrierException::AUTH);
    }

    // ---- Preview -------------------------------------------------------------------------------------------

    /** The ways of paying "Buat resi" asks the agent to pick from when the lead's is unknown or only read from the chat. */
    public const PAYMENTS = ['cod' => 'COD (bayar di tempat)', 'transfer' => 'Transfer (sudah lunas)'];

    /**
     * How the buyer pays, as far as "Buat resi" may trust it: what the agent picked in the preview, else the lead's
     * own when an agent, the order form or a confirmed order set it. Never assumed: blank or only read from the
     * chat is null, and the agent has to pick.
     */
    public static function payment(Lead $lead, ?string $choice = null): ?string
    {
        if (isset(self::PAYMENTS[$choice])) {
            return $choice;
        }

        return filled($lead->payment_type) && $lead->payment_type_source === null ? (string) $lead->payment_type : null;
    }

    /** Paid in advance (transfer, QRIS…) but nobody has seen the money: no paid_at and no proof of payment. */
    public static function unpaid(Lead $lead, ?string $payment): bool
    {
        return $payment !== null && $payment !== 'cod' && $lead->paid_at === null && $lead->payment_proof_at === null;
    }

    /** What the chat says about paying, for the agent to confirm: the lead's unconfirmed one, else read from the messages. */
    public function paymentSuggestion(Lead $lead): ?string
    {
        if (filled($lead->payment_type) && $lead->payment_type_source !== null) {
            return (string) $lead->payment_type;
        }

        if (filled($lead->payment_type)) {
            return null;
        }

        $found = app(ChatShippingDetails::class)->fromConversation($lead, save: false);

        return $found['changes']['payment_type'] ?? null;
    }

    /** @param  array{location_id?: ?string, rate_code?: ?string, payment?: ?string}  $choice  what the agent picked in the preview */
    public function draft(Lead $lead, array $choice = []): ShipmentDraft
    {
        $account = $this->account();
        $draft = new ShipmentDraft;
        $draft->shipmentType = (string) ($account?->setting('shipment_type') ?: 'PICKUP');

        if ($account === null) {
            $draft->problems[] = 'Pengiriman otomatis belum diatur di Pengaturan > Pengiriman.';

            return $draft;
        }

        foreach (['sender_name' => 'nama toko pengirim', 'sender_phone' => 'nomor HP pengirim', 'sender_address' => 'alamat pengirim', 'origin_id' => 'lokasi asal Everpro', 'origin_postal' => 'kode pos asal (pilih ulang lokasi asal)'] as $key => $label) {
            if (blank($key === 'origin_postal' ? $this->originPostal($account) : $account->setting($key))) {
                $draft->problems[] = 'Lengkapi '.$label.' di Pengaturan > Pengiriman.';
            }
        }

        $edit = array_filter((array) ($choice['edit'] ?? []), fn ($v) => $v !== null && $v !== '');
        $rp = fn (?int $v) => 'Rp'.number_format((int) $v, 0, ',', '.');
        $draft->problems = array_merge($draft->problems, $this->leadProblems($lead));
        $draft->items = $this->items($lead);
        $draft->goodsTotal = array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $draft->items));
        $draft->itemValue = $draft->goodsTotal;
        $draft->description = Str::limit(implode(', ', array_map(fn ($i) => $i['name'].' x'.$i['quantity'], $draft->items)), 150, '');
        $draft->weightGrams = $this->weight($lead);
        $draft->dimensions = $this->dimensions($lead);
        $draft->note = ((array) $lead->shipping_address_parts)['landmark'] ?? null;
        $draft->insurance = (bool) $account->setting('insurance', false);

        // What the agent changed in the form, each against what was calculated
        $use = function (string $key, string $label, mixed $value, ?callable $show = null) use ($draft, $edit) {
            if (! array_key_exists($key, $edit) || $edit[$key] == $value) {
                return $value;
            }

            $draft->edits[$label] = [$show ? $show($value) : $value, $show ? $show($edit[$key]) : $edit[$key]];

            return $edit[$key];
        };
        $kg = fn ($g) => rtrim(rtrim(number_format(((int) $g) / 1000, 2, ',', '.'), '0'), ',').' kg';
        $draft->description = Str::limit(trim((string) $use('description', 'Isi paket', $draft->description)), 150, '');
        $draft->itemValue = (int) $use('item_value', 'Nilai barang', $draft->itemValue, $rp);
        $draft->weightGrams = (int) $use('weight_grams', 'Berat', $draft->weightGrams, $kg);
        $draft->note = $use('note', 'Catatan kurir', $draft->note, fn ($v) => filled($v) ? $v : '-') ?: null;
        $draft->insurance = (bool) $use('insurance', 'Asuransi', $draft->insurance, fn ($v) => $v ? 'ya' : 'tidak');

        if (isset($edit['length'], $edit['width'], $edit['height'])) {
            $dims = ['length' => (int) $edit['length'], 'width' => (int) $edit['width'], 'height' => (int) $edit['height']];
            $show = fn (?array $d) => $d ? $d['length'].'×'.$d['width'].'×'.$d['height'].' cm' : '-';

            if ($dims !== $draft->dimensions) {
                $draft->edits['Dimensi'] = [$show($draft->dimensions), $show($dims)];
                $draft->dimensions = $dims;
            }
        }

        if ($draft->weightGrams <= 0) {
            $draft->problems[] = 'Berat paket harus lebih dari 0 kg.';
        }
        $draft->payment = self::payment($lead, $choice['payment'] ?? null);
        $draft->cod = $draft->payment === 'cod';
        $draft->unpaid = self::unpaid($lead, $draft->payment);

        if ($draft->payment === null) {
            $draft->problems[] = 'Cara bayar belum dipastikan: pilih COD atau Transfer (sudah lunas).';
        }

        if ($draft->items === []) {
            $draft->problems[] = 'Produk dan jumlah pesanan belum diisi di lead.';
        } elseif ($draft->goodsTotal <= 0 && $draft->itemValue <= 0) {
            $draft->problems[] = 'Nilai barang belum diketahui: isi harga produk di katalog atau di pesanan lead.';
        }

        if ($draft->problems !== []) {
            return $draft;
        }

        try {
            $provider = $this->provider();

            // A number the provider would refuse is said here, before it is called.
            if ($provider instanceof ValidatesPhones && ($phones = $provider->phoneProblems((string) $account->setting('sender_phone'), $lead->phone)) !== []) {
                $draft->problems = $phones;

                return $draft;
            }

            [$draft->destination, $draft->candidates] = $this->destination($provider, $lead, $choice['location_id'] ?? null);

            if ($draft->destination === null) {
                $draft->problems[] = $draft->candidates === []
                    ? 'Alamat pembeli tidak ditemukan di daftar lokasi Everpro. Periksa kecamatan, kelurahan dan kode pos di lead.'
                    : 'Pilih kelurahan tujuan yang tepat dari daftar.';

                return $draft;
            }

            if (blank($draft->destination->postalCode)) {
                $draft->problems[] = 'Kode pos kelurahan tujuan tidak diketahui. Isi kode pos di alamat lead.';

                return $draft;
            }

            $draft->rates = $provider->rates(new RateRequest(
                (string) $account->setting('origin_id'), $draft->destination->id, $draft->weightGrams, $draft->itemValue, $draft->cod,
                $draft->insurance, $draft->shipmentType, $draft->dimensions,
                (string) $this->originPostal($account), $draft->destination->postalCode, $this->originSubDistrict($account), $draft->destination->subDistrict ?: null,
                $draft->cod ? (int) ($edit['cod_amount'] ?? $draft->goodsTotal + (int) ($edit['charged'] ?? $lead->shipping_cost ?? 0)) : null,
            ));
        } catch (CarrierException $e) {
            $draft->problems[] = $e->getMessage();

            return $draft;
        }

        $wanted = $choice['rate_code'] ?? null;
        $draft->rate = collect($draft->rates)->first(fn (CarrierRate $r) => $wanted !== null && $r->key() === $wanted)
            ?? collect($draft->rates)->first(fn (CarrierRate $r) => $wanted !== null && $r->code === $wanted)
            ?? collect($draft->rates)->first(fn (CarrierRate $r) => $r->matches($lead->courier))
            ?? collect($draft->rates)->first(fn (CarrierRate $r) => $r->matches((string) $account->setting('service', 'JNE REG')));

        if ($draft->rate === null) {
            $draft->problems[] = $draft->rates === [] ? 'Tidak ada kurir yang melayani tujuan ini.' : 'Pilih kurir dan layanan.';

            return $draft;
        }

        // What the buyer was charged (already after the product's shipping promo), else the courier's price less
        // that promo. Everpro is still paid its own price; the difference is the shop's subsidy.
        // The agent may change both (what was agreed in the chat); each change is kept with what was calculated.
        [$off, $promo] = ShippingPromos::best($this->shippingItems($lead), $draft->destination->province ?: $lead->shipping_province, $draft->rate->price);
        $draft->promoCharged = $draft->rate->price - $off;
        $draft->recordedCharged = $lead->shipping_cost !== null ? (int) $lead->shipping_cost : null;
        $draft->charged = (int) $use('charged', 'Ongkir ditagih', $draft->recordedCharged ?? $draft->promoCharged, $rp);
        $draft->promo = $draft->charged === $draft->promoCharged ? $promo : null;
        $draft->calculatedCod = $draft->cod ? $draft->goodsTotal + $draft->charged + $draft->rate->codFee : 0;
        $draft->codAmount = $draft->cod ? (int) $use('cod_amount', 'Total COD', $draft->calculatedCod, $rp) : 0;

        if ($draft->charged < 0 || ($draft->cod && $draft->codAmount <= 0)) {
            $draft->problems[] = $draft->charged < 0 ? 'Ongkir ditagih tidak boleh minus.' : 'Total COD harus lebih dari Rp0.';
        }

        if ($draft->shipmentType === 'PICKUP') {
            try {
                $draft->pickupTimes = $this->provider()->pickupTimes($draft->rate->code);
            } catch (CarrierException $e) {
                report($e);
            }
        }

        return $draft;
    }

    /** The sender's postal code: kept with the origin, else the one at the end of its label ("... (17540)"). */
    private function originPostal(CarrierAccount $account): ?string
    {
        $code = (string) $account->setting('origin_postal');

        return preg_match('/^\d{5}$/', $code) ? $code : (preg_match('/\((\d{5})\)\s*$/', (string) $account->setting('origin_label'), $m) ? $m[1] : null);
    }

    /** The sender's kelurahan: kept with the origin, else the first part of its label ("Sekeloa, Coblong, ..."). */
    private function originSubDistrict(CarrierAccount $account): ?string
    {
        $name = $account->setting('origin_sub_district') ?: preg_replace('/\s*\(\d{5}\)\s*$/', '', explode(',', (string) $account->setting('origin_label'))[0]);

        return filled($name) ? trim((string) $name) : null;
    }

    /** @return list<string> */
    public function leadProblems(Lead $lead): array
    {
        $problems = [];
        $parts = (array) $lead->shipping_address_parts;

        if (blank($lead->name) || mb_strlen(trim($lead->name)) < 2) {
            $problems[] = 'Nama penerima belum diisi.';
        }

        if (PhoneNumber::whatsapp($lead->phone) === null) {
            $problems[] = 'Nomor HP penerima tidak valid: perbaiki nomor HP lead (10–15 angka, mis. 0812…).';
        }

        if (mb_strlen(trim((string) $lead->shipping_address)) < 10) {
            $problems[] = 'Alamat kirim lengkap belum diisi.';
        } elseif (blank($parts['kode_pos'] ?? null) && blank($parts['kecamatan'] ?? null)) {
            $problems[] = 'Kecamatan atau kode pos tujuan belum diisi di alamat lead.';
        }

        return $problems;
    }

    /** @return list<array{name: string, quantity: int, price: int, product_id: ?int}> */
    public function items(Lead $lead): array
    {
        $items = [];

        foreach ((array) $lead->order_items as $item) {
            $quantity = (int) ($item['quantity'] ?? $item['jumlah'] ?? 0);
            $name = trim((string) ($item['name'] ?? $item['produk'] ?? ''));

            if ($quantity > 0 && $name !== '') {
                $items[] = ['name' => trim($name.' '.($item['variant'] ?? '')), 'quantity' => $quantity, 'price' => (int) ($item['price'] ?? 0), 'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null];
            }
        }

        if ($items === [] && $lead->property_id !== null) {
            $product = $lead->property;
            $items[] = ['name' => trim(($product?->name ?? 'Pesanan').' '.$lead->variant), 'quantity' => max(1, (int) $lead->quantity), 'price' => (int) ($product?->price_from ?? 0), 'product_id' => $lead->property_id];
        }

        return $items;
    }

    /** The billable weight in grams: the catalogue weight of every item (or the default), rounded up per whole kg. */
    public function weight(Lead $lead): int
    {
        return (new ZoneShippingRates(ShippingSettings::of($this->current->get())))->weight($this->shippingItems($lead))[2] * 1000;
    }

    /** @return list<ShippingItem> the order's items with their catalogue products */
    private function shippingItems(Lead $lead): array
    {
        $products = Property::query()->whereIn('id', array_filter(array_column($this->items($lead), 'product_id')))->get()->keyBy('id');

        return array_map(fn ($i) => new ShippingItem($products[$i['product_id']] ?? null, $i['name'], null, $i['quantity']), $this->items($lead));
    }

    private function dimensions(Lead $lead): ?array
    {
        $product = $lead->property;

        return $product && $product->length_cm && $product->width_cm && $product->height_cm
            ? ['length' => (int) $product->length_cm, 'width' => (int) $product->width_cm, 'height' => (int) $product->height_cm * max(1, (int) $lead->quantity)]
            : null;
    }

    /**
     * The provider's place for the buyer's address: by postal code, then by kelurahan/kecamatan/kota. Certain only
     * when one place matches the kelurahan (or only one comes back); otherwise the agent picks.
     *
     * @return array{0: ?CarrierLocation, 1: list<CarrierLocation>}
     */
    public function destination(CarrierProvider $provider, Lead $lead, ?string $chosen = null): array
    {
        $parts = (array) $lead->shipping_address_parts;
        $area = implode(', ', array_filter([$parts['kelurahan'] ?? null, $parts['kecamatan'] ?? null, $parts['kota'] ?? null], fn ($v) => is_string($v) && trim($v) !== ''));
        $candidates = $provider->locations($parts['kode_pos'] ?? null, $area ?: null);

        if ($candidates === [] && filled($parts['kode_pos'] ?? null) && $area !== '') {
            $candidates = $provider->locations(null, $area);
        }

        if (filled($chosen)) {
            return [collect($candidates)->first(fn (CarrierLocation $l) => $l->id === (string) $chosen), $candidates];
        }

        $norm = fn (?string $v) => trim(preg_replace('/^(kel(urahan)?|desa|ds|kec(amatan)?)\.?\s+/i', '', mb_strtolower(trim((string) $v))));
        $kelurahan = $norm($parts['kelurahan'] ?? null);
        $kecamatan = $norm($parts['kecamatan'] ?? null);

        $matches = collect($candidates)->filter(fn (CarrierLocation $l) => $kelurahan !== '' && $norm($l->subDistrict) === $kelurahan
            && ($kecamatan === '' || $l->district === null || $norm($l->district) === $kecamatan))->values();

        if ($matches->count() === 1) {
            return [$matches->first(), $candidates];
        }

        return [count($candidates) === 1 && $kelurahan === '' ? $candidates[0] : null, $candidates];
    }

    // ---- Booking -------------------------------------------------------------------------------------------

    /**
     * Keeps the parcel and queues the provider calls. A lead with a parcel still open gets that parcel back.
     *
     * @return array{0: Shipment, 1: bool} the parcel, and whether it is new
     */
    public function book(Lead $lead, ShipmentDraft $draft, ?User $actor = null, bool $automatic = false): array
    {
        if (! $draft->ok()) {
            throw new CarrierException(implode(' ', $draft->problems) ?: 'Data kiriman belum lengkap.');
        }

        $account = $this->account();

        $result = DB::transaction(function () use ($lead, $draft, $account, $actor, $automatic) {
            Lead::query()->whereKey($lead->getKey())->lockForUpdate()->first();
            $open = Shipment::query()->where('lead_id', $lead->getKey())->whereIn('state', [Shipment::PENDING, Shipment::ORDERED, Shipment::SHIPPED])->latest('id')->first();

            if ($open !== null) {
                return [$open, false];
            }

            $retry = Shipment::query()->where('lead_id', $lead->getKey())->where('state', Shipment::FAILED)->whereNull('order_id')->latest('id')->first();
            $sequence = (int) Shipment::query()->where('lead_id', $lead->getKey())->max('sequence') + 1;
            $attributes = [
                'provider' => $account->provider, 'courier' => $draft->rate->name(), 'rate_code' => $draft->rate->code, 'state' => Shipment::PENDING, 'error' => null,
                'is_cod' => $draft->cod, 'cod_amount' => $draft->cod ? $draft->codAmount : null, 'price' => $draft->rate->price, 'charged' => $draft->charged,
                'weight_grams' => $draft->weightGrams, 'automatic' => $automatic,
                'request' => [
                    'destination' => $draft->destination->toArray(), 'item_value' => $draft->itemValue, 'description' => $draft->description,
                    'pickup_time' => $draft->pickupTimes[0] ?? null, 'shipment_type' => $draft->shipmentType, 'by' => $actor?->getKey(), 'rate' => $draft->rate->meta,
                    'dimensions' => $draft->dimensions, 'note' => $draft->note, 'insurance' => $draft->insurance, 'edits' => $draft->edits ?: null,
                ],
            ];

            if ($retry !== null) {
                $retry->forceFill($attributes)->save();

                return [$retry, true];
            }

            $shipment = Shipment::query()->create($attributes + [
                'lead_id' => $lead->getKey(), 'sequence' => $sequence,
                'client_order_no' => 'JL-'.$this->current->id().'-'.$lead->getKey().'-'.$sequence,
            ]);

            return [$shipment, true];
        });

        if ($result[1]) {
            $lead->activities()->create(['user_id' => $actor?->getKey(), 'type' => 'note', 'body' => ($automatic ? 'Otomatis membuat' : 'Membuat').' kiriman '.$result[0]->courier.' lewat '.config('carriers.labels.'.$result[0]->provider, $result[0]->provider).' ('.$result[0]->client_order_no.').']);
            CreateShipment::dispatch($result[0]->getKey(), $this->current->id());
        }

        return $result;
    }

    /**
     * "Coba lagi": a parcel the provider refused is sent again as it was (the details the agent checked or changed,
     * the same order number), once the cause is fixed. Never while the lead has another parcel on its way.
     */
    public function retry(Shipment $shipment, ?User $actor = null): void
    {
        $retried = DB::transaction(function () use ($shipment) {
            Lead::query()->whereKey($shipment->lead_id)->lockForUpdate()->first();
            $fresh = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->retryable()) {
                throw new CarrierException('Kiriman ini tidak bisa dicoba ulang.');
            }

            if (Shipment::query()->where('lead_id', $fresh->lead_id)->whereKeyNot($fresh->getKey())->whereIn('state', [Shipment::PENDING, Shipment::ORDERED, Shipment::SHIPPED])->exists()) {
                throw new CarrierException('Lead ini sudah punya kiriman lain yang aktif.');
            }

            $fresh->forceFill(['state' => Shipment::PENDING, 'error' => null])->save();

            return $fresh;
        });

        $retried->lead->activities()->create(['user_id' => $actor?->getKey(), 'type' => 'note', 'body' => 'Mencoba lagi kiriman '.$retried->client_order_no.' ('.$retried->courier.').']);
        CreateShipment::dispatch($retried->getKey(), $this->current->id());
    }

    /** The provider calls for a booked parcel (runs on the queue). Safe to run again: the order number is the same. */
    public function place(Shipment $shipment): void
    {
        if (! in_array($shipment->state, [Shipment::PENDING, Shipment::ORDERED], true)) {
            return;
        }

        $account = $this->account() ?? throw new CarrierException('Pengiriman otomatis belum diatur.', CarrierException::AUTH);
        $provider = $this->provider();
        $lead = $shipment->lead;
        $request = (array) $shipment->request;
        $parts = (array) $lead->shipping_address_parts;
        $shipment->forceFill(['attempts' => $shipment->attempts + 1])->save();

        if ($shipment->state === Shipment::PENDING) {
            $order = $provider->createOrder(new OrderRequest(
                $shipment->client_order_no, (string) $shipment->rate_code,
                [
                    'name' => (string) $account->setting('sender_name'), 'phone' => LocalPhone::number((string) $account->setting('sender_phone')),
                    'address' => (string) $account->setting('sender_address'), 'location_id' => (string) $account->setting('origin_id'), 'postal_code' => $this->originPostal($account),
                    'sub_district' => $this->originSubDistrict($account),
                ],
                [
                    'name' => trim($lead->name), 'phone' => LocalPhone::number($lead->phone), 'address' => trim((string) $lead->shipping_address),
                    'location_id' => (string) data_get($request, 'destination.id'), 'postal_code' => data_get($request, 'destination.postalCode') ?: ($parts['kode_pos'] ?? null),
                    'sub_district' => data_get($request, 'destination.subDistrict') ?: null, 'note' => array_key_exists('note', $request) ? $request['note'] : ($parts['landmark'] ?? null),
                ],
                (int) $shipment->weight_grams, (string) ($request['description'] ?? ''), (int) ($request['item_value'] ?? 0),
                $shipment->is_cod, (int) $shipment->cod_amount, (int) $shipment->price, (bool) ($request['insurance'] ?? $account->setting('insurance', false)),
                (string) ($request['shipment_type'] ?? 'PICKUP'), $request['pickup_time'] ?? null, $request['dimensions'] ?? null, (array) ($request['rate'] ?? []),
            ));

            $shipment->forceFill(array_filter([
                'state' => Shipment::ORDERED, 'order_id' => $order->orderId, 'price' => $order->price, 'cod_amount' => $shipment->is_cod ? ($order->codAmount ?? $shipment->cod_amount) : null,
            ], fn ($v) => $v !== null))->save();

            if ($order->awb !== null) {
                $this->awbIssued($shipment, $order->awb);

                return;
            }
        }

        $awb = $provider->generateAwb($shipment->client_order_no);

        if ($awb !== null) {
            $this->awbIssued($shipment, $awb);
        }
    }

    /** A tracking number came (from placing the order or from the provider's callback). Runs once per parcel. */
    public function awbIssued(Shipment $shipment, string $awb): void
    {
        $awb = strtoupper(preg_replace('/\s+/', '', $awb));

        $first = DB::transaction(function () use ($shipment, $awb) {
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->first();

            if ($locked->awb_notified_at !== null || $locked->state === Shipment::CANCELLED) {
                return false;
            }

            $locked->forceFill(['awb' => $awb, 'state' => Shipment::SHIPPED, 'error' => null, 'awb_notified_at' => now(),
                'status' => ShipmentStatus::rank($locked->status) > 0 ? $locked->status : ShipmentStatus::AWB_CREATED])->save();
            $shipment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $first) {
            return;
        }

        $lead = $shipment->lead;
        $lead->forceFill(['shipping_cost' => $lead->shipping_cost ?? $shipment->charged])->save();
        $this->addEvents($shipment, [new TrackEvent(now()->toIso8601String(), 'AWB', ShipmentStatus::AWB_CREATED, 'Resi '.$awb.' terbit ('.$shipment->courier.')')]);

        try {
            $shipment->forceFill(['label_url' => $this->provider()->label($awb)])->save();
        } catch (CarrierException $e) {
            // The label is fetched again when someone presses "Cetak label".
        }

        // Kept for the Pengiriman menu; tried again on the queue when the provider has no label yet.
        if ($shipment->printable()) {
            SaveShipmentLabel::dispatch($shipment->getKey(), (int) $shipment->tenant_id)->afterCommit();
        }

        // The existing tracking flow: number on the lead, WhatsApp to the buyer (once), lead to Dikemas & dikirim.
        $note = app(StageAutomation::class)->shipped($lead, $shipment->courier, $awb, $this->actor($shipment));
        $lead->activities()->create(['type' => 'note', 'body' => 'Resi '.$awb.' ('.$shipment->courier.') terbit otomatis. '.$note]);
    }

    public function failed(Shipment $shipment, CarrierException $e): void
    {
        $shipment->forceFill(['state' => $shipment->order_id === null && $shipment->state === Shipment::PENDING ? Shipment::FAILED : $shipment->state, 'error' => Str::limit($e->getMessage(), 250, '')])->save();
        $shipment->lead->activities()->create(['type' => 'note', 'body' => 'Kiriman '.$shipment->client_order_no.' belum berhasil dibuat: '.$e->getMessage()]);

        if ($shipment->automatic) {
            $shipment->lead->forceFill(['auto_shipment_state' => AutoShipments::FAILED, 'auto_shipment_note' => Str::limit('Resi gagal dibuat otomatis: '.$e->getMessage(), 490, '')])->save();
        }

        $this->notices->alertStaff($shipment, $shipment->automatic ? 'Resi gagal dibuat' : 'Kiriman belum berhasil dibuat', $e->getMessage().($shipment->automatic ? ' Tidak dicoba ulang otomatis.' : ''));
    }

    // ---- Labels and cancelling -----------------------------------------------------------------------------

    public function label(Shipment $shipment): string
    {
        if (blank($shipment->awb)) {
            throw new CarrierException('Resi belum terbit, label belum bisa dicetak.');
        }

        $url = $this->provider()->label($shipment->awb);
        $shipment->forceFill(['label_url' => $url])->save();

        return $url;
    }

    public function cancel(Shipment $shipment, User $actor, string $reason = 'Dibatalkan toko'): void
    {
        if (! $shipment->cancellable()) {
            throw new CarrierException('Kiriman ini sudah diambil kurir atau sudah selesai, tidak bisa dibatalkan.');
        }

        $this->provider()->cancel($shipment->client_order_no, $reason);
        $shipment->forceFill(['state' => Shipment::CANCELLED, 'status' => ShipmentStatus::CANCELLED, 'cancelled_at' => now(), 'final_at' => now()])->save();
        $this->addEvents($shipment, [new TrackEvent(now()->toIso8601String(), 'CANCELED', ShipmentStatus::CANCELLED, 'Kiriman dibatalkan oleh '.$actor->name)]);
        $shipment->lead->activities()->create(['user_id' => $actor->getKey(), 'type' => 'note', 'body' => 'Kiriman '.($shipment->awb ?? $shipment->client_order_no).' dibatalkan: '.$reason]);
    }

    // ---- Tracking ------------------------------------------------------------------------------------------

    /** A provider callback for this agency: a tracking number, or a status. */
    public function callback(CallbackEvent $event): ?Shipment
    {
        $shipment = Shipment::query()
            ->where(fn ($q) => $q->when($event->clientOrderNo, fn ($q) => $q->where('client_order_no', $event->clientOrderNo))
                ->when($event->awb, fn ($q) => $q->orWhere('awb', strtoupper($event->awb))))
            ->latest('id')->first();

        if ($shipment === null) {
            Log::info('Shipping callback for an unknown parcel', ['tenant' => $this->current->id(), 'code' => $event->code]);

            return null;
        }

        if (filled($event->awb) && $shipment->awb_notified_at === null) {
            $this->awbIssued($shipment, $event->awb);
            $shipment->refresh();
        }

        if (filled($event->code)) {
            $this->apply($shipment, new TrackResult((string) ($shipment->awb ?? $event->awb), $event->status, $event->code, $event->reason, [
                new TrackEvent(now()->toIso8601String(), $event->code, $event->status, $event->description ?? ShipmentStatus::label($event->status ?? '').($event->reason ? ': '.$event->reason : '')),
            ]));
        }

        return $shipment;
    }

    /** Tracks one parcel now and applies what came back. */
    public function refresh(Shipment $shipment): Shipment
    {
        if (blank($shipment->awb)) {
            return $shipment;
        }

        $this->apply($shipment, $this->provider()->track($shipment->awb));

        return $shipment->refresh();
    }

    /** Applies a parcel's status: history kept, a later status moves the parcel on and tells the buyer once. */
    public function apply(Shipment $shipment, TrackResult $result): void
    {
        $this->addEvents($shipment, $result->events);
        $shipment->forceFill(['tracked_at' => now()])->save();

        if ($result->status === null) {
            if (filled($result->code)) {
                Log::info('Unknown parcel status', ['tenant' => $this->current->id(), 'shipment' => $shipment->getKey(), 'code' => $result->code]);
            }

            return;
        }

        $changed = DB::transaction(function () use ($shipment, $result) {
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->first();

            if (! ShipmentStatus::advances($locked->status, $result->status) || $locked->state === Shipment::CANCELLED) {
                return false;
            }

            $locked->forceFill([
                'status' => $result->status, 'raw_status' => Str::limit((string) $result->code, 60, ''), 'status_reason' => $result->reason,
                'final_at' => in_array($result->status, ShipmentStatus::FINAL, true) ? now() : null,
                'state' => $result->status === ShipmentStatus::CANCELLED ? Shipment::CANCELLED : $locked->state,
            ])->save();
            $shipment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $changed) {
            return;
        }

        $eventAt = $result->at !== null ? Carbon::parse($result->at) : now();
        $this->notices->statusChanged($shipment, $result->status, $eventAt);
    }

    // ---- Tracking numbers made elsewhere --------------------------------------------------------------------

    /** "jne 0012 3456" => "JNE00123456". */
    public static function normalizeAwb(?string $awb): string
    {
        return strtoupper(preg_replace('/[\s.]+/', '', (string) $awb));
    }

    /** Why a typed tracking number cannot be one: 8 to 30 letters, digits or dashes, with at least four digits. */
    public static function awbProblem(string $awb): ?string
    {
        return preg_match('/^[A-Z0-9-]{8,30}$/', $awb) && preg_match_all('/\d/', $awb) >= 4 ? null : 'Format nomor resi tidak wajar (8-30 huruf/angka).';
    }

    /** The lead (other than $lead) this agency already gave the number to. */
    public static function awbOwner(string $awb, ?Lead $lead = null): ?Lead
    {
        $shipment = Shipment::query()->where('awb', $awb)->where('state', '!=', Shipment::CANCELLED)
            ->when($lead, fn ($q) => $q->where('lead_id', '!=', $lead->getKey()))->with('lead')->first();

        return $shipment?->lead ?? Lead::query()->where('tracking_number', $awb)->when($lead, fn ($q) => $q->whereKeyNot($lead->getKey()))->first();
    }

    /**
     * Links a tracking number made outside JITU LEAD (e.g. in the provider's own app) to the lead: a parcel with
     * source "manual" and no order, the number on the lead, then one tracking call. What the parcel went through
     * before it was linked is history only: no message for it. Only later changes reach the buyer as usual.
     * A parcel already delivered closes the lead (Selesai) without any message, dated to the delivery so a
     * review follow-up whose time has passed is not sent.
     *
     * @return array{0: Shipment, 1: string} the parcel, and what tracking said, for the agent
     */
    public function link(Lead $lead, string $awb, ?string $courier, ?User $actor = null, bool $notify = false, bool $closeDelivered = true): array
    {
        $awb = self::normalizeAwb($awb);
        $courier = filled($courier) ? trim($courier) : 'JNE';

        if ($problem = self::awbProblem($awb)) {
            throw new CarrierException($problem);
        }

        $account = $this->account() ?? throw new CarrierException('Pengiriman otomatis belum diatur di Pengaturan > Pengiriman.', CarrierException::AUTH);

        $shipment = DB::transaction(function () use ($lead, $awb, $courier, $account, $actor) {
            Lead::query()->whereKey($lead->getKey())->lockForUpdate()->first();

            if ($other = self::awbOwner($awb, $lead)) {
                throw new CarrierException('Resi '.$awb.' sudah dipakai lead lain ('.$other->name.').');
            }

            $open = Shipment::query()->where('lead_id', $lead->getKey())->whereIn('state', [Shipment::PENDING, Shipment::ORDERED, Shipment::SHIPPED])->latest('id')->first();

            if ($open !== null && $open->awb === $awb) {
                return null;
            }

            if ($open !== null) {
                throw new CarrierException('Lead ini sudah punya kiriman aktif'.($open->awb ? ' (resi '.$open->awb.')' : '').'. Batalkan dulu bila resinya berbeda.');
            }

            $sequence = (int) Shipment::query()->where('lead_id', $lead->getKey())->max('sequence') + 1;

            return Shipment::query()->create([
                'lead_id' => $lead->getKey(), 'provider' => $account->provider, 'source' => Shipment::MANUAL, 'sequence' => $sequence,
                'client_order_no' => 'MAN-'.$this->current->id().'-'.$lead->getKey().'-'.$sequence, 'awb' => $awb, 'courier' => $courier,
                'state' => Shipment::SHIPPED, 'status' => ShipmentStatus::AWB_CREATED, 'awb_notified_at' => now(), 'linked_at' => now(),
                'request' => array_filter(['by' => $actor?->getKey()]),
            ]);
        });

        if ($shipment === null) {
            $existing = Shipment::latestFor($lead);

            return [$existing, 'Resi '.$awb.' sudah terhubung ke lead ini.'];
        }

        if ($notify) {
            // The usual tracking message (once) and the move to Dikemas & dikirim.
            $note = app(StageAutomation::class)->shipped($lead, $courier, $awb, $actor);
        } else {
            $lead->forceFill(['tracking_number' => $awb, 'courier' => $courier, 'shipped_at' => $lead->shipped_at ?? now()])->save();
            $note = 'Nomor resi disimpan tanpa pesan ke pembeli.';
        }

        $lead->activities()->create(['user_id' => $actor?->getKey(), 'type' => 'note', 'body' => 'Resi '.$awb.' ('.$courier.') dihubungkan'.($actor ? ' oleh '.$actor->name : '').'. '.$note]);

        try {
            $result = $this->trackOnce($shipment);
        } catch (CarrierException $e) {
            return [$shipment->refresh(), 'Resi terhubung, tapi belum bisa dilacak sekarang: '.$e->getMessage()];
        }

        if ($result === null) {
            return [$shipment->refresh(), 'Resi terhubung, tapi resi belum dikenali '.Carriers::label($account->provider).'. Dicoba lagi saat pelacakan berikutnya.'];
        }

        $this->baseline($shipment, $result, $closeDelivered);

        return [$shipment->refresh(), 'Status sekarang: '.ShipmentStatus::label($shipment->status ?? ShipmentStatus::AWB_CREATED).'.'
            .($shipment->status === ShipmentStatus::DELIVERED && $lead->fresh()->stage?->type === StageType::Won ? ' Lead dipindah ke Selesai tanpa pesan.' : '')];
    }

    /** The scheduled tracking of a linked number: one call; three calls that do not know it stop the tracking. */
    public function trackLinked(Shipment $shipment): void
    {
        $result = $this->trackOnce($shipment);

        if ($result !== null) {
            $this->apply($shipment, $result);
        }
    }

    /**
     * One tracking call for a linked number. Null when the provider does not know it (counted; the third time the
     * parcel is marked untrackable and noted on the lead). Other failures are thrown and not counted.
     */
    private function trackOnce(Shipment $shipment): ?TrackResult
    {
        try {
            $result = $this->provider()->track((string) $shipment->awb);
        } catch (CarrierException $e) {
            if ($e->retryable() || $e->kind === CarrierException::AUTH || ! in_array($e->httpStatus, [400, 404, 422], true)) {
                throw $e;
            }

            $result = null;
        }

        if ($result !== null && ($result->status !== null || $result->events !== [])) {
            $shipment->forceFill(['track_failures' => 0])->save();

            return $result;
        }

        $failures = $shipment->track_failures + 1;
        $shipment->forceFill(['track_failures' => $failures, 'tracked_at' => now()] + ($failures >= Shipment::MAX_TRACK_FAILURES ? ['untrackable_at' => now()] : []))->save();

        if ($failures === Shipment::MAX_TRACK_FAILURES) {
            $shipment->lead->activities()->create(['type' => 'note', 'body' => 'Resi '.$shipment->awb.' tidak bisa dilacak: tidak dikenali '.Carriers::label($shipment->provider)
                .' setelah '.$failures.' kali dicoba (mungkin dibuat di luar akun ini). Pelacakan otomatis dihentikan; status kiriman perlu dicek manual.']);
        }

        return null;
    }

    /** The status a parcel already had when it was linked: stored as history, never told to the buyer. */
    private function baseline(Shipment $shipment, TrackResult $result, bool $closeDelivered): void
    {
        $this->addEvents($shipment, $result->events);
        $status = $result->status !== null && ShipmentStatus::rank($result->status) > ShipmentStatus::rank(ShipmentStatus::AWB_CREATED) ? $result->status : ShipmentStatus::AWB_CREATED;

        $shipment->forceFill([
            'status' => $status, 'raw_status' => Str::limit((string) $result->code, 60, ''), 'status_reason' => $result->reason, 'tracked_at' => now(),
            'final_at' => in_array($status, ShipmentStatus::FINAL, true) ? now() : null,
            'state' => $status === ShipmentStatus::CANCELLED ? Shipment::CANCELLED : $shipment->state,
        ])->save();

        if ($status === ShipmentStatus::DELIVERED && $closeDelivered) {
            $lead = $shipment->lead;

            if (app(StageAutomation::class)->parcelDelivered($lead) !== null) {
                // Dated to the delivery: follow-ups counted from Selesai whose time has passed are not sent.
                $deliveredAt = $result->at !== null ? Carbon::parse($result->at) : now();
                $lead->forceFill(['stage_entered_at' => $deliveredAt->min(now())->setTimezone(config('app.timezone'))])->save();
            }
        }
    }

    /** @param  list<TrackEvent>  $events */
    private function addEvents(Shipment $shipment, array $events): void
    {
        if ($events === []) {
            return;
        }

        $known = collect((array) $shipment->events);
        $seen = $known->map(fn ($e) => ($e['code'] ?? '').'|'.($e['description'] ?? ''))->all();

        foreach ($events as $event) {
            $key = ($event->code ?? '').'|'.$event->description;

            if (! in_array($key, $seen, true)) {
                $known->push($event->toArray());
                $seen[] = $key;
            }
        }

        $shipment->forceFill(['events' => $known->sortBy('at')->values()->take(-100)->values()->all()])->save();
    }

    private function actor(Shipment $shipment): ?User
    {
        $by = data_get($shipment->request, 'by');

        return $by ? User::query()->find($by) : null;
    }

    /** The parcels still on their way, for the scheduled tracking. */
    public static function unfinished()
    {
        return Shipment::query()->where('state', Shipment::SHIPPED)->whereNull('final_at')->whereNotNull('awb')->whereNull('untrackable_at');
    }
}
