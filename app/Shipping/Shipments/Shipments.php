<?php

namespace App\Shipping\Shipments;

use App\Automation\StageAutomation;
use App\Jobs\CreateShipment;
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
use App\Shipping\ShippingItem;
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

    /** @param  array{location_id?: ?string, rate_code?: ?string}  $choice  what the agent picked in the preview */
    public function draft(Lead $lead, array $choice = []): ShipmentDraft
    {
        $account = $this->account();
        $draft = new ShipmentDraft;
        $draft->shipmentType = (string) ($account?->setting('shipment_type') ?: 'PICKUP');

        if ($account === null) {
            $draft->problems[] = 'Pengiriman otomatis belum diatur di Pengaturan > Pengiriman.';

            return $draft;
        }

        foreach (['sender_name' => 'nama toko pengirim', 'sender_phone' => 'nomor HP pengirim', 'sender_address' => 'alamat pengirim', 'origin_id' => 'lokasi asal Everpro'] as $key => $label) {
            if (blank($account->setting($key))) {
                $draft->problems[] = 'Lengkapi '.$label.' di Pengaturan > Pengiriman.';
            }
        }

        $draft->problems = array_merge($draft->problems, $this->leadProblems($lead));
        $draft->items = $this->items($lead);
        $draft->itemValue = array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $draft->items));
        $draft->description = Str::limit(implode(', ', array_map(fn ($i) => $i['name'].' x'.$i['quantity'], $draft->items)), 150, '');
        $draft->weightGrams = $this->weight($lead);
        $draft->cod = $lead->payment_type === 'cod';

        if ($draft->items === []) {
            $draft->problems[] = 'Produk dan jumlah pesanan belum diisi di lead.';
        }

        if ($draft->problems !== []) {
            return $draft;
        }

        try {
            $provider = $this->provider();
            [$draft->destination, $draft->candidates] = $this->destination($provider, $lead, $choice['location_id'] ?? null);

            if ($draft->destination === null) {
                $draft->problems[] = $draft->candidates === []
                    ? 'Alamat pembeli tidak ditemukan di daftar lokasi Everpro. Periksa kecamatan, kelurahan dan kode pos di lead.'
                    : 'Pilih kelurahan tujuan yang tepat dari daftar.';

                return $draft;
            }

            $draft->rates = $provider->rates(new RateRequest(
                (string) $account->setting('origin_id'), $draft->destination->id, $draft->weightGrams, $draft->itemValue, $draft->cod,
                (bool) $account->setting('insurance', false), $draft->shipmentType, $this->dimensions($lead),
            ));
        } catch (CarrierException $e) {
            $draft->problems[] = $e->getMessage();

            return $draft;
        }

        $wanted = $choice['rate_code'] ?? null;
        $draft->rate = collect($draft->rates)->first(fn (CarrierRate $r) => $wanted !== null && $r->code === $wanted)
            ?? collect($draft->rates)->first(fn (CarrierRate $r) => $r->matches($lead->courier))
            ?? collect($draft->rates)->first(fn (CarrierRate $r) => $r->matches((string) $account->setting('service', 'JNE REG')));

        if ($draft->rate === null) {
            $draft->problems[] = $draft->rates === [] ? 'Tidak ada kurir yang melayani tujuan ini.' : 'Pilih kurir dan layanan.';

            return $draft;
        }

        // What the buyer was charged (a shipping promo shows here), else the courier's price.
        $draft->charged = $lead->shipping_cost !== null ? (int) $lead->shipping_cost : $draft->rate->price;
        $draft->codAmount = $draft->cod ? $draft->itemValue + $draft->charged + $draft->rate->codFee : 0;

        if ($draft->shipmentType === 'PICKUP') {
            try {
                $draft->pickupTimes = $this->provider()->pickupTimes($draft->rate->code);
            } catch (CarrierException $e) {
                report($e);
            }
        }

        return $draft;
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
            $problems[] = 'Nomor HP penerima belum valid.';
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
        $settings = ShippingSettings::of($this->current->get());
        $products = Property::query()->whereIn('id', array_filter(array_column($this->items($lead), 'product_id')))->get()->keyBy('id');
        $items = array_map(fn ($i) => new ShippingItem($products[$i['product_id']] ?? null, $i['name'], null, $i['quantity']), $this->items($lead));

        return (new ZoneShippingRates($settings))->weight($items)[2] * 1000;
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
    public function book(Lead $lead, ShipmentDraft $draft, ?User $actor = null): array
    {
        if (! $draft->ok()) {
            throw new CarrierException(implode(' ', $draft->problems) ?: 'Data kiriman belum lengkap.');
        }

        $account = $this->account();

        $result = DB::transaction(function () use ($lead, $draft, $account, $actor) {
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
                'weight_grams' => $draft->weightGrams,
                'request' => [
                    'destination' => $draft->destination->toArray(), 'item_value' => $draft->itemValue, 'description' => $draft->description,
                    'pickup_time' => $draft->pickupTimes[0] ?? null, 'shipment_type' => $draft->shipmentType, 'by' => $actor?->getKey(),
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
            $lead->activities()->create(['user_id' => $actor?->getKey(), 'type' => 'note', 'body' => 'Membuat kiriman '.$result[0]->courier.' lewat '.config('carriers.labels.'.$result[0]->provider, $result[0]->provider).' ('.$result[0]->client_order_no.').']);
            CreateShipment::dispatch($result[0]->getKey(), $this->current->id());
        }

        return $result;
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
                    'address' => (string) $account->setting('sender_address'), 'location_id' => (string) $account->setting('origin_id'), 'postal_code' => $account->setting('origin_postal'),
                ],
                [
                    'name' => trim($lead->name), 'phone' => LocalPhone::number($lead->phone), 'address' => trim((string) $lead->shipping_address),
                    'location_id' => (string) data_get($request, 'destination.id'), 'postal_code' => $parts['kode_pos'] ?? data_get($request, 'destination.postalCode'),
                    'note' => $parts['landmark'] ?? null,
                ],
                (int) $shipment->weight_grams, (string) ($request['description'] ?? ''), (int) ($request['item_value'] ?? 0),
                $shipment->is_cod, (int) $shipment->cod_amount, (int) $shipment->price, (bool) $account->setting('insurance', false),
                (string) ($request['shipment_type'] ?? 'PICKUP'), $request['pickup_time'] ?? null,
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

        // The existing tracking flow: number on the lead, WhatsApp to the buyer (once), lead to Dikemas & dikirim.
        $note = app(StageAutomation::class)->shipped($lead, $shipment->courier, $awb, $this->actor($shipment));
        $lead->activities()->create(['type' => 'note', 'body' => 'Resi '.$awb.' ('.$shipment->courier.') terbit otomatis. '.$note]);
    }

    public function failed(Shipment $shipment, CarrierException $e): void
    {
        $shipment->forceFill(['state' => $shipment->order_id === null && $shipment->state === Shipment::PENDING ? Shipment::FAILED : $shipment->state, 'error' => Str::limit($e->getMessage(), 250, '')])->save();
        $shipment->lead->activities()->create(['type' => 'note', 'body' => 'Kiriman '.$shipment->client_order_no.' belum berhasil dibuat: '.$e->getMessage()]);
        $this->notices->alertStaff($shipment, 'Kiriman belum berhasil dibuat', $e->getMessage());
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
        return Shipment::query()->where('state', Shipment::SHIPPED)->whereNull('final_at')->whereNotNull('awb');
    }
}
