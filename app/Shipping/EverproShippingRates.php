<?php

namespace App\Shipping;

use App\Models\CarrierAccount;
use App\Models\Tenant;
use App\Regions\RegionDirectory;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\CarrierProvider;
use App\Shipping\Carriers\CarrierRate;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\RateRequest;
use App\Shipping\Shipments\Shipments;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buyers' shipping prices from Everpro's live rates (/shipment/v2/rates), the same call "Buat resi" makes, so what
 * the buyer is told is what the parcel costs. Priced with the same sender, weight (catalogue weight rounded up per
 * kg), service type and insurance as "Buat resi", for the destination's postal code (from the address form, else
 * matched from its kelurahan/kecamatan through the location list).
 *
 * The zone table stays the fallback: when the postal code is not known, the shop has no active Everpro account,
 * or Everpro fails or is slow (config shipping.everpro.timeout seconds), the zone table prices it as before and
 * the quote says why (ShippingQuote::sourceNote). A zone the shop marked "konfirmasi admin" still goes to an admin.
 * Everpro's answers are cached per sender, destination postal code and weight for a few hours.
 */
class EverproShippingRates implements ShippingProvider
{
    public function __construct(private ShippingSettings $settings, private ?Tenant $tenant = null) {}

    public function quote(ShippingRequest $request): ShippingQuote
    {
        $zones = new ZoneShippingRates($this->settings);
        $zone = $zones->quote($request);

        // Nothing to price yet (no city, no product), or the shop wants an admin for that zone.
        if (in_array($zone->status, [ShippingQuote::UNCLEAR_DESTINATION, ShippingQuote::NOT_AVAILABLE], true) || $zones->confirmsAdmin($zone->zone)) {
            return $zone;
        }

        $why = null;

        try {
            $options = $this->options($request, $zone, $why);
        } catch (Throwable $e) {
            Log::warning('Everpro shipping quote failed, zone table used', ['tenant' => $this->tenant?->getKey(), 'error' => mb_substr($e->getMessage(), 0, 200)]);
            [$options, $why] = [null, 'Everpro tidak bisa dihubungi'];
        }

        if ($options === null) {
            return $zone->copy(['sourceNote' => $why]);
        }

        return $zone->copy([
            'status' => ShippingQuote::OK, 'message' => 'Ongkir dihitung dari tarif Everpro.', 'options' => $options,
            'zone' => null, 'source' => ShippingSettings::EVERPRO, 'sourceNote' => null,
        ]);
    }

    /** @return list<ShippingOption>|null null (with $why) when Everpro cannot price every courier asked */
    private function options(ShippingRequest $request, ShippingQuote $zone, ?string &$why): ?array
    {
        $account = Carriers::configured($this->tenant) ? Carriers::account($this->tenant) : null;

        if ($account === null || $account->provider !== 'everpro') {
            $why = 'akun Everpro belum aktif';

            return null;
        }

        $origin = Shipments::originPostal($account);

        if ($origin === null) {
            $why = 'lokasi asal Everpro belum diatur';

            return null;
        }

        $provider = Carriers::make($account);

        if (method_exists($provider, 'withTimeout')) {
            $provider->withTimeout((int) config('shipping.everpro.timeout', 3));
        }

        [$postal, $subDistrict] = $this->destination($provider, $request);

        if ($postal === null) {
            $why = 'kode pos/kelurahan tujuan belum diketahui';

            return null;
        }

        $rates = $this->rates($provider, $account, $origin, $postal, $subDistrict, $zone);
        $options = [];

        foreach ($request->couriers ?? $this->settings->couriers() as $courier) {
            $rate = collect($rates)->first(fn (CarrierRate $r) => $r->category() === 'regular' && $r->matches($courier))
                ?? collect($rates)->first(fn (CarrierRate $r) => $r->matches($courier));

            if ($rate === null) {
                $why = 'Everpro tidak punya tarif '.$courier.' ke tujuan ini';

                return null;
            }

            $options[] = new ShippingOption($courier, $rate->price, $rate->etaMin, $rate->etaMax);
        }

        return $options === [] ? null : $options;
    }

    /**
     * The destination's postal code and kelurahan: as given, else the one place of Everpro's list that matches the
     * kelurahan (and kecamatan), else the one postal code of the kecamatan. Null when it is not certain.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function destination(CarrierProvider $provider, ShippingRequest $request): array
    {
        if ($request->postalCode !== null) {
            return [$request->postalCode, $request->subDistrict];
        }

        if (blank($request->subDistrict) && blank($request->district)) {
            return [null, null];
        }

        $norm = fn (?string $v) => RegionDirectory::searchKey(preg_replace('/^(kel(urahan)?|desa|ds|kec(amatan)?)\.?\s+/iu', '', trim((string) $v)));
        $places = $provider->locations(null, implode(', ', array_filter([$request->subDistrict, $request->district, $request->city], fn ($v) => filled($v))));
        $inDistrict = fn (CarrierLocation $l) => blank($request->district) || $l->district === null || $norm($l->district) === $norm($request->district);

        if (filled($request->subDistrict)) {
            $found = array_values(array_filter($places, fn (CarrierLocation $l) => $norm($l->subDistrict) === $norm($request->subDistrict) && $inDistrict($l) && filled($l->postalCode)));

            return count($found) === 1 ? [$found[0]->postalCode, $found[0]->subDistrict] : [null, null];
        }

        $codes = array_values(array_unique(array_map(fn (CarrierLocation $l) => $l->postalCode,
            array_filter($places, fn (CarrierLocation $l) => $l->district !== null && $inDistrict($l) && filled($l->postalCode)))));

        return count($codes) === 1 ? [$codes[0], null] : [null, null];
    }

    /** @return list<CarrierRate> Everpro's rates for this parcel, from the cache when asked for recently */
    private function rates(CarrierProvider $provider, CarrierAccount $account, string $origin, string $postal, ?string $subDistrict, ShippingQuote $zone): array
    {
        $grams = $zone->billableKg * 1000;
        $insurance = (bool) $account->setting('insurance', false);
        $type = (string) ($account->setting('shipment_type') ?: 'PICKUP');
        $dimensions = $this->dimensions($zone->items);
        $originSub = Shipments::originSubDistrict($account);
        $key = 'everpro-quote:'.$account->tenant_id.':'.($account->sandbox ? 's' : 'p').':'.sha1(json_encode([$origin, $originSub, $postal, $subDistrict, $grams, $type, $insurance, $dimensions]));

        if (is_array($hit = Cache::get($key))) {
            return array_map(fn (array $r) => new CarrierRate(...$r), $hit);
        }

        $rates = $provider->rates(new RateRequest(
            (string) $account->setting('origin_id'), '', $grams, max(1, (int) $zone->subtotal()), false, $insurance, $type, $dimensions,
            $origin, $postal, $originSub, $subDistrict,
        ));

        if ($rates !== []) {
            Cache::put($key, array_map(fn (CarrierRate $r) => [
                'code' => $r->code, 'courier' => $r->courier, 'service' => $r->service, 'price' => $r->price, 'codFee' => $r->codFee,
                'insuranceFee' => $r->insuranceFee, 'etaMin' => $r->etaMin, 'etaMax' => $r->etaMax,
                'meta' => array_intersect_key($r->meta, array_flip(['category', 'rate_code', 'rate_name'])),
            ], $rates), now()->addHours((int) config('shipping.everpro.cache_hours', 6)));
        }

        return $rates;
    }

    /** As "Buat resi" sends them: the first product's size, its height times the quantity. */
    private function dimensions(array $items): ?array
    {
        $first = $items[0] ?? null;
        $product = $first?->product;
        $quantity = array_sum(array_map(fn (ShippingItem $i) => $i->quantity, $items));

        return $product && $product->length_cm && $product->width_cm && $product->height_cm
            ? ['length' => (int) $product->length_cm, 'width' => (int) $product->width_cm, 'height' => (int) $product->height_cm * max(1, $quantity)]
            : null;
    }
}
