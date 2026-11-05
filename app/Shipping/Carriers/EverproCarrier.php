<?php

namespace App\Shipping\Carriers;

use App\Models\CarrierAccount;
use App\Regions\RegionDirectory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Everpro Open API (prepaid), https://developer.everpro.id/prepaid/.
 *
 * Auth: POST /auth/v1/token with the agency's client key and secret gives a bearer token for 24 hours. It is
 * cached per agency (encrypted) until five minutes before it runs out; a 401 drops it and asks for a new one
 * once. Every answer comes in {data, message, status, status_code}. Field names Everpro does not document
 * fully (rates, tracking) are read from the documented ones first, then common alternatives.
 *
 * Nothing logged here holds the credentials, a token or the buyer's details.
 */
class EverproCarrier implements CarrierProvider
{
    private const TOKEN_MARGIN = 300;

    /** Postal codes looked up at most for one search by name. */
    private const MAX_POSTAL_LOOKUPS = 8;

    public function __construct(private CarrierAccount $account) {}

    public function key(): string
    {
        return 'everpro';
    }

    public function baseUrl(): string
    {
        $url = $this->account->sandbox ? config('carriers.everpro.sandbox_url') : config('carriers.everpro.base_url');

        if (blank($url)) {
            throw new CarrierException('Alamat API Everpro production belum diatur (EVERPRO_BASE_URL). Hubungi admin JITU LEAD, atau pakai mode sandbox.', CarrierException::UNAVAILABLE);
        }

        return rtrim((string) $url, '/');
    }

    // ---- Account -------------------------------------------------------------------------------------------

    /** GET /balance/v1/detail answers {"data": {"cash": 125000, "name": "..."}}; other names are read as a fallback. */
    public function balance(): ?int
    {
        $data = $this->call('get', '/balance/v1/detail');

        if (is_numeric($data)) {
            return (int) $data;
        }

        foreach (['cash', 'balance', 'available_balance', 'amount', 'saldo', 'balance.cash'] as $key) {
            if (is_numeric($value = data_get($data, $key))) {
                return (int) $value;
            }
        }

        // Only the keys, never the values, so the field can be added here without exposing the account.
        Log::warning('Everpro balance not found in the answer', ['tenant' => $this->account->tenant_id, 'keys' => is_array($data) ? array_keys($data) : gettype($data)]);

        return null;
    }

    // ---- Places --------------------------------------------------------------------------------------------

    /**
     * By postal code: POST /location/v1/postalcode (it only takes a 5-digit code). By name ("kedungwaringin",
     * "Sekeloa, Coblong, Kota Bandung"): the postal codes of matching villages and districts, from the imported
     * regions (App\Regions\RegionDirectory) or else Everpro's own list (/location/v1/sub-districts by name,
     * /location/v1/districts by name then their sub-districts), then those codes through /postalcode, keeping the
     * places whose kelurahan, kecamatan or kota match the text. Only answers that worked are cached.
     */
    public function locations(?string $postalCode, ?string $area = null): array
    {
        $postalCode = preg_match('/^\d{5}$/', $code = preg_replace('/\D/', '', (string) $postalCode)) ? $code : null;
        $terms = array_values(array_unique(array_filter(array_map(
            fn ($t) => RegionDirectory::searchKey($t), preg_split('/[,\n]+/', mb_substr((string) $area, 0, 150)),
        ), fn ($t) => mb_strlen($t) >= 3)));

        if ($postalCode !== null) {
            return $this->rank($this->byPostalCode($postalCode), $terms, keepAll: true);
        }

        if ($terms === []) {
            return [];
        }

        // The most specific word first (kelurahan, then kecamatan, then kota); local regions before Everpro's list.
        foreach ($terms as $term) {
            foreach ([fn () => RegionDirectory::available() ? RegionDirectory::postalCodesFor($term) : [], fn () => $this->postalCodesFromEverpro($term)] as $source) {
                $places = [];

                foreach (array_slice(array_values(array_unique($source())), 0, self::MAX_POSTAL_LOOKUPS) as $code) {
                    array_push($places, ...$this->byPostalCode($code));
                }

                if (($found = $this->rank($places, $terms)) !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    /** @return list<CarrierLocation> */
    private function byPostalCode(string $code): array
    {
        $rows = $this->cached('postal:'.$code, function () use ($code) {
            $data = $this->call('post', '/location/v1/postalcode', ['postal_code' => $code]);

            return array_values(array_filter(array_map(fn ($r) => is_array($r) && filled($r['sub_district_id'] ?? null) ? [
                'id' => (string) $r['sub_district_id'], 'subDistrict' => (string) ($r['sub_district_name'] ?? ''), 'district' => $r['district_name'] ?? null,
                'city' => $r['city_name'] ?? null, 'province' => $r['province_name'] ?? null, 'postalCode' => isset($r['postal_code']) ? (string) $r['postal_code'] : $code,
            ] : null, (array) (Arr::isList((array) $data) ? $data : data_get($data, 'locations', [])))));
        });

        return array_map(CarrierLocation::fromArray(...), $rows);
    }

    /** @return list<string> postal codes of Everpro sub-districts named $term, or of the sub-districts of districts named $term */
    private function postalCodesFromEverpro(string $term): array
    {
        $codes = fn ($rows) => array_values(array_filter(array_map(fn ($r) => is_array($r) && preg_match('/^\d{5}$/', (string) ($r['postal_code'] ?? '')) ? (string) $r['postal_code'] : null, (array) $rows)));
        $named = fn ($rows, string $field) => array_values(array_filter((array) $rows, fn ($r) => is_array($r) && str_starts_with(RegionDirectory::searchKey($r[$field] ?? ''), $term)));

        $villages = $this->cached('sub-districts:'.$term, fn () => (array) $this->call('get', '/location/v1/sub-districts', ['sub_district_name' => $term]));
        $found = $codes($named($villages, 'sub_district_name'));

        $districts = $this->cached('districts:'.$term, fn () => (array) $this->call('get', '/location/v1/districts', ['district_name' => $term]));

        foreach (array_slice($named($districts, 'district_name'), 0, 3) as $district) {
            if (filled($district['district_id'] ?? null)) {
                $children = $this->cached('sub-districts-of:'.$district['district_id'], fn () => (array) $this->call('get', '/location/v1/sub-districts', ['district_id' => $district['district_id']]));
                array_push($found, ...$codes($children));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Places matching the most words of the text first (kelurahan before kecamatan before kota); without $keepAll
     * the ones matching none are dropped.
     *
     * @param  list<CarrierLocation>  $places
     * @param  list<string>  $terms
     * @return list<CarrierLocation>
     */
    private function rank(array $places, array $terms, bool $keepAll = false): array
    {
        $unique = collect($places)->unique(fn (CarrierLocation $l) => $l->id);

        if ($terms === []) {
            return $unique->values()->all();
        }

        // An exact name counts double a name that only starts with the text (someone still typing).
        $match = fn (?string $name, string $t) => ($k = RegionDirectory::searchKey($name)) === $t ? 2 : (str_starts_with($k, $t) ? 1 : 0);
        $score = fn (CarrierLocation $l) => collect($terms)->sum(fn ($t) => $match($l->subDistrict, $t) * 4 + $match($l->district, $t) * 2 + $match($l->city, $t));

        return $unique->map(fn ($l) => [$l, $score($l)])->filter(fn ($p) => $keepAll || $p[1] > 0)
            ->sortBy([[fn ($p) => $p[1], 'desc'], [fn ($p) => $p[0]->subDistrict, 'asc']])->map(fn ($p) => $p[0])->values()->all();
    }

    /**
     * Cached for 30 days when the call worked. "Not found" (404) is an empty answer that is not kept, nor is any
     * error, so a slow or failing Everpro never leaves an empty search behind.
     */
    private function cached(string $key, \Closure $fetch): array
    {
        $key = 'everpro-loc2:'.($this->account->sandbox ? 's' : 'p').':'.sha1($key);

        if (is_array($hit = Cache::get($key))) {
            return $hit;
        }

        try {
            $rows = (array) $fetch();
        } catch (CarrierException $e) {
            if ($e->httpStatus === 404) {
                return [];
            }

            throw $e;
        }

        if ($rows !== []) {
            Cache::put($key, $rows, now()->addDays(30));
        }

        return $rows;
    }

    // ---- Prices and pickup -------------------------------------------------------------------------------

    public function rates(RateRequest $request): array
    {
        $data = $this->call('post', '/shipment/v2/rates', array_filter([
            'origin' => ['id' => $request->originId],
            'destination' => ['id' => $request->destinationId],
            'package' => [
                'weight' => max(1, $request->weightGrams),
                'price' => max(0, $request->itemValue),
                'type' => 'REGULAR',
                'dimension' => [
                    'length' => (int) ($request->dimensions['length'] ?? 1), 'width' => (int) ($request->dimensions['width'] ?? 1), 'height' => (int) ($request->dimensions['height'] ?? 1),
                ],
            ],
            'shipment_type' => $request->shipmentType,
            'payment_type' => $request->cod ? 'COD' : null,
            'is_use_insurance' => $request->insurance,
        ], fn ($v) => $v !== null));

        $rows = Arr::isList((array) $data) ? (array) $data : (array) (data_get($data, 'rates') ?? data_get($data, 'logistics') ?? []);
        $out = [];

        foreach ($rows as $row) {
            // Grouped per courier ({logistic, rates: [...]}) or flat.
            foreach (isset($row['rates']) && is_array($row['rates']) ? array_map(fn ($r) => $r + ['logistic_name' => $row['logistic_name'] ?? $row['name'] ?? null, 'logistic_code' => $row['logistic_code'] ?? $row['code'] ?? null], $row['rates']) : [$row] as $rate) {
                $code = $rate['rate_code'] ?? $rate['code'] ?? null;
                $price = Arr::first([$rate['shipment_price'] ?? null, $rate['final_price'] ?? null, $rate['price'] ?? null, $rate['shipping_cost'] ?? null], fn ($v) => is_numeric($v));

                if (blank($code) || $price === null || (isset($rate['is_active']) && ! $rate['is_active'])) {
                    continue;
                }

                $out[] = new CarrierRate(
                    (string) $code,
                    (string) ($rate['logistic_name'] ?? data_get($rate, 'logistic.name') ?? $rate['logistic_code'] ?? ''),
                    (string) ($rate['rate_name'] ?? $rate['service_name'] ?? $rate['service'] ?? data_get($rate, 'rate.name') ?? ''),
                    (int) $price,
                    (int) ($rate['cod_fee'] ?? $rate['cod_price'] ?? $rate['cod_amount'] ?? 0),
                    (int) ($rate['insurance_fee'] ?? $rate['insurance_price'] ?? 0),
                    isset($rate['min_duration']) ? (int) $rate['min_duration'] : null,
                    isset($rate['max_duration']) ? (int) $rate['max_duration'] : null,
                );
            }
        }

        return $out;
    }

    public function pickupTimes(string $rateCode): array
    {
        $data = $this->call('get', '/shipment/v1/pickup-times', ['rate_codes' => $rateCode, 'timezone' => 'Asia/Jakarta']);
        $times = [];

        foreach ((array) data_get($data, 'available_pickup_time', []) as $courier) {
            foreach ((array) ($courier['pickup_time_list'] ?? []) as $day) {
                foreach ((array) ($day['pickup_times'] ?? []) as $slot) {
                    if (filled($slot['start_at'] ?? null)) {
                        $times[] = Carbon::parse($slot['start_at'])->toIso8601String();
                    }
                }
            }
        }

        sort($times);

        return array_values(array_unique($times));
    }

    // ---- Orders --------------------------------------------------------------------------------------------

    public function createOrder(OrderRequest $order): CarrierOrder
    {
        try {
            $data = $this->call('post', '/shipment/v2/orders', array_filter([
                'client_order_no' => $order->clientOrderNo,
                'rate_code' => $order->rateCode,
                'shipment_type' => $order->shipmentType,
                'pickup_time' => $order->pickupTime,
                'is_cod' => $order->cod,
                'cod_price' => $order->cod ? $order->codAmount : 0,
                'shipment_price' => $order->shippingPrice,
                'is_use_insurance' => $order->insurance,
                'package_count' => 1,
                'package_desc' => mb_substr($order->description, 0, 150),
                'package_price' => $order->itemValue,
                'package_type_id' => 1,
                'weight' => max(1, $order->weightGrams),
                'length' => (int) ($order->dimensions['length'] ?? 1),
                'width' => (int) ($order->dimensions['width'] ?? 1),
                'height' => (int) ($order->dimensions['height'] ?? 1),
                'shipper_name' => $order->shipper['name'],
                'shipper_phone' => $order->shipper['phone'],
                'shipper_email' => $order->shipper['email'] ?? null,
                'shipper_address' => $order->shipper['address'],
                'shipper_sub_district_id' => $order->shipper['location_id'],
                'shipper_postal_code' => $order->shipper['postal_code'] ?? null,
                'receiver_name' => $order->receiver['name'],
                'receiver_phone' => $order->receiver['phone'],
                'receiver_address' => $order->receiver['address'],
                'receiver_address_note' => $order->receiver['note'] ?? null,
                'receiver_sub_district_id' => $order->receiver['location_id'],
                'receiver_postal_code' => $order->receiver['postal_code'] ?? null,
            ], fn ($v) => $v !== null));
        } catch (CarrierException $e) {
            // The number is already booked (a retry after a timeout): carry on with that order.
            if ($e->httpStatus === 409 || preg_match('/already|sudah (ada|digunakan|terdaftar)|duplicate/i', $e->getMessage())) {
                return new CarrierOrder($order->clientOrderNo, null);
            }

            throw $e;
        }

        return new CarrierOrder(
            (string) ($data['client_order_no'] ?? $order->clientOrderNo),
            isset($data['order_reference_id']) ? (string) $data['order_reference_id'] : null,
            filled($data['awb_number'] ?? null) ? (string) $data['awb_number'] : null,
            isset($data['actual_price']) ? (int) $data['actual_price'] : (isset($data['requested_price']) ? (int) $data['requested_price'] : null),
            isset($data['actual_cod_price']) ? (int) $data['actual_cod_price'] : null,
        );
    }

    public function generateAwb(string $clientOrderNo): ?string
    {
        $data = $this->call('post', '/shipment/v1/orders/'.rawurlencode($clientOrderNo).'/generate');
        $awb = data_get($data, 'awb_number');

        if (blank($awb)) {
            $awb = data_get($this->call('get', '/shipment/v1/orders/'.rawurlencode($clientOrderNo).'/awb'), 'awb_number');
        }

        return filled($awb) ? (string) $awb : null;
    }

    public function label(string $awb): string
    {
        $url = data_get($this->call('get', '/shipment/v1/orders/'.rawurlencode($awb).'/label'), 'url_label_pdf');

        if (blank($url)) {
            throw new CarrierException('Everpro belum menyediakan label untuk resi ini. Coba lagi beberapa saat.', CarrierException::UNAVAILABLE);
        }

        return (string) $url;
    }

    public function track(string $awb): TrackResult
    {
        return $this->trackResult($awb, (array) $this->call('get', '/shipment/v1/orders/'.rawurlencode($awb).'/track'));
    }

    public function trackMany(array $awbs): array
    {
        $out = [];

        foreach (array_chunk(array_values(array_unique($awbs)), 50) as $chunk) {
            $data = (array) $this->call('post', '/shipment/v1/orders/track', ['awb_numbers' => $chunk]);

            foreach (Arr::isList($data) ? $data : (array) data_get($data, 'orders', []) as $row) {
                $awb = (string) ($row['awb_number'] ?? '');

                if ($awb !== '' && in_array($awb, $chunk, true)) {
                    $out[$awb] = $this->trackResult($awb, (array) $row);
                }
            }
        }

        return $out;
    }

    public function cancel(string $clientOrderNo, string $reason): void
    {
        $this->call('post', '/shipment/v1/orders/'.rawurlencode($clientOrderNo).'/cancel', ['reason' => mb_substr($reason, 0, 200), 'cancel_reason' => mb_substr($reason, 0, 200)]);
    }

    // ---- Callback ------------------------------------------------------------------------------------------

    public function verifyCallback(Request $request): bool
    {
        $expected = $this->account->credential('callback_key');
        $given = (string) $request->header('x-everpro-key', '');

        return $expected !== null && $given !== '' && hash_equals($expected, $given);
    }

    public function parseCallback(Request $request): ?CallbackEvent
    {
        $awb = $request->input('awb_number');
        $order = $request->input('client_order_no');
        $code = $request->input('tracking_code') ?? $request->input('status') ?? $request->input('status_id');

        if (blank($awb) && blank($order)) {
            return null;
        }

        return new CallbackEvent(
            filled($awb) ? (string) $awb : null,
            filled($order) ? (string) $order : null,
            filled($code) ? (string) $code : null,
            filled($code) ? ShipmentStatus::normalize($this->key(), (string) $code) : null,
            $this->reason($request->all()),
            filled($request->input('description')) ? mb_substr((string) $request->input('description'), 0, 250) : null,
        );
    }

    // ---- HTTP ----------------------------------------------------------------------------------------------

    private function trackResult(string $awb, array $data): TrackResult
    {
        $events = [];

        foreach ((array) ($data['tracking_history'] ?? $data['histories'] ?? $data['history'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = isset($row['status']) ? (string) $row['status'] : (isset($row['status_id']) ? (string) $row['status_id'] : null);
            $events[] = new TrackEvent(
                filled($row['date'] ?? null) ? Carbon::parse($row['date'])->toIso8601String() : now()->toIso8601String(),
                $code,
                ShipmentStatus::normalize($this->key(), $code),
                mb_substr((string) ($row['description'] ?? $row['note'] ?? ''), 0, 250),
            );
        }

        usort($events, fn (TrackEvent $a, TrackEvent $b) => strcmp($a->at, $b->at));

        // The order's own status first; else the newest history step that we know.
        $code = Arr::first([$data['tracking_code'] ?? null, $data['status_id'] ?? null, $data['status'] ?? null], fn ($v) => filled($v) && ShipmentStatus::normalize($this->key(), (string) $v) !== null)
            ?? $data['tracking_code'] ?? $data['status'] ?? null;
        $status = filled($code) ? ShipmentStatus::normalize($this->key(), (string) $code) : null;

        if ($status === null) {
            $last = collect($events)->reverse()->first(fn (TrackEvent $e) => $e->status !== null);
            [$code, $status] = $last ? [$last->code, $last->status] : [$code, null];
        }

        return new TrackResult($awb, $status, filled($code) ? (string) $code : null, $this->reason($data), $events, $events !== [] ? end($events)->at : null);
    }

    private function reason(array $data): ?string
    {
        $reason = $data['cancel_reason'] ?? $data['reason'] ?? $data['failed_reason'] ?? $data['note'] ?? null;

        return filled($reason) && is_string($reason) ? mb_substr(trim($reason), 0, 200) : null;
    }

    /** One call with the agency's token; a 401 gets a fresh token once. Returns the "data" part of the answer. */
    private function call(string $method, string $path, array $payload = [], bool $retried = false): mixed
    {
        try {
            $client = $this->http()->withToken($this->token());
            $response = $method === 'get' ? $client->get($path, $payload) : $client->{$method}($path, $payload);
        } catch (ConnectionException) {
            Log::warning('Everpro call failed: no connection', ['tenant' => $this->account->tenant_id, 'path' => $this->safePath($path)]);

            throw new CarrierException('Everpro tidak bisa dihubungi. Coba lagi beberapa saat.', CarrierException::UNAVAILABLE);
        }

        if ($response->status() === 401 && ! $retried) {
            $this->forgetToken();

            return $this->call($method, $path, $payload, true);
        }

        if (! $response->successful()) {
            throw $this->failure($response, $path);
        }

        return $response->json('data');
    }

    private function failure(Response $response, string $path): CarrierException
    {
        $status = $response->status();
        $message = trim((string) ($response->json('message') ?: (is_array($response->json('errors')) ? implode('; ', Arr::flatten($response->json('errors'))) : '')));

        Log::warning('Everpro call failed', ['tenant' => $this->account->tenant_id, 'path' => $this->safePath($path), 'status' => $status, 'message' => mb_substr($message, 0, 200)]);

        if ($status === 401 || $status === 403) {
            return new CarrierException('Kredensial Everpro ditolak. Periksa client key dan client secret di Pengaturan > Pengiriman.', CarrierException::AUTH, $status);
        }

        if (preg_match('/saldo|balance|insufficient/i', $message)) {
            return new CarrierException('Saldo Everpro tidak cukup. Isi saldo di dashboard Everpro, lalu coba lagi.', CarrierException::BALANCE, $status);
        }

        if ($status >= 500 || $status === 429) {
            return new CarrierException('Everpro sedang tidak bisa memproses (kode '.$status.'). Coba lagi beberapa saat.', CarrierException::UNAVAILABLE, $status);
        }

        return new CarrierException('Everpro menolak permintaan'.($message !== '' ? ': '.mb_substr($message, 0, 200) : ' (kode '.$status.')').'.', CarrierException::REJECTED, $status);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->asJson()->timeout((int) config('carriers.timeout', 20))->connectTimeout(10);
    }

    private function tokenKey(): string
    {
        return 'everpro-token:'.$this->account->tenant_id.':'.($this->account->sandbox ? 's' : 'p').':'.substr(sha1((string) $this->account->credential('client_key')), 0, 12);
    }

    private function token(): string
    {
        $cached = Cache::get($this->tokenKey());

        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (\Throwable) {
                Cache::forget($this->tokenKey());
            }
        }

        if (! $this->account->hasCredentials()) {
            throw new CarrierException('Client key dan client secret Everpro belum diisi di Pengaturan > Pengiriman.', CarrierException::AUTH);
        }

        try {
            $response = $this->http()->post('/auth/v1/token', ['client_key' => $this->account->credential('client_key'), 'client_secret' => $this->account->credential('client_secret')]);
        } catch (ConnectionException) {
            throw new CarrierException('Everpro tidak bisa dihubungi. Coba lagi beberapa saat.', CarrierException::UNAVAILABLE);
        }

        $token = $response->json('data.token') ?? $response->json('data.access_token');

        if (! $response->successful() || blank($token)) {
            throw $response->successful() ? new CarrierException('Everpro tidak memberi token.', CarrierException::AUTH) : $this->failure($response, '/auth/v1/token');
        }

        $ttl = max(60, (int) ($response->json('data.expires') ?? $response->json('data.expires_in') ?? 86400) - self::TOKEN_MARGIN);
        Cache::put($this->tokenKey(), Crypt::encryptString((string) $token), $ttl);

        return (string) $token;
    }

    private function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    /** The path without a tracking or order number, for logs. */
    private function safePath(string $path): string
    {
        return preg_replace('#/orders/[^/]+#', '/orders/{id}', $path);
    }
}
