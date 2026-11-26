<?php

namespace App\Shipping;

use App\Ai\LeadQuote;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Tenant;
use App\Regions\RegionDirectory;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Carriers\CarrierProvider;
use App\Shipping\Carriers\CarrierRate;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\RateRequest;
use App\Shipping\Shipments\ProviderLocations;
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
 *
 * A "konfirmasi admin" zone (NTB, NTT, Maluku, Papua at Quicker Sport) is priced from Everpro too, for any shop with an
 * active Everpro account, whatever its price source (forAdminZone(), called by Shipping::quote): only for the lead's
 * own address once it is matched to Everpro's place for certain, with the order's weight. When Everpro fails, the
 * place is not certain, or Everpro has no service there, the zone's flag still sends it to an admin, with the reason.
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

    public const ADMIN_EVERPRO_FAILED = 'Everpro gagal atau tidak merespons saat cek ongkir';

    public const ADMIN_PLACE_UNCERTAIN = 'lokasi tujuan belum dikenali persis di Everpro';

    public const ADMIN_NO_SERVICE = 'Everpro tidak punya layanan kurir toko ke tujuan ini';

    /**
     * A zone the shop marked "konfirmasi admin", priced from Everpro for the lead's address: an OK quote (source
     * everpro) or the same "konfirmasi admin" quote with adminReason. Null when the shop has no active Everpro
     * account: nothing changes for it.
     */
    public function forAdminZone(ShippingRequest $request, ShippingQuote $zone, ?Lead $lead): ?ShippingQuote
    {
        $account = Carriers::configured($this->tenant) ? Carriers::account($this->tenant) : null;

        if ($account === null || $account->provider !== 'everpro') {
            return null;
        }

        $admin = fn (string $why) => $zone->copy(['adminReason' => $why, 'message' => 'Ongkir ke zona '.$zone->zone.' perlu dikonfirmasi admin ('.$why.').']);
        $place = $this->leadPlace($lead, $zone);
        $origin = Shipments::originPostal($account);
        $postal = $place?->postalCode ?: ($lead !== null ? LeadQuote::destination($lead)['kode_pos'] ?? null : null);

        if ($place === null || $postal === null || $zone->items === []) {
            return $admin(self::ADMIN_PLACE_UNCERTAIN);
        }

        if ($origin === null) {
            return $admin('lokasi asal Everpro belum diatur');
        }

        try {
            $provider = Carriers::make($account);

            if (method_exists($provider, 'withTimeout')) {
                $provider->withTimeout((int) config('shipping.everpro.timeout', 3));
            }

            $rates = $this->rates($provider, $account, $origin, $postal, $place->subDistrict ?: null, $zone);
        } catch (Throwable $e) {
            Log::warning('Everpro quote for a confirm-admin zone failed', ['tenant' => $this->tenant?->getKey(), 'lead' => $lead?->getKey(), 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return $admin(self::ADMIN_EVERPRO_FAILED);
        }

        // The couriers asked (the primary one by default) that Everpro serves there; else the shop's other couriers.
        $options = $this->served($rates, $request->couriers ?? $this->settings->couriers());

        if ($options === [] && $request->couriers !== null) {
            $options = array_slice($this->served($rates, $this->settings->couriers()), 0, 1);
        }

        if ($options === []) {
            return $admin(self::ADMIN_NO_SERVICE);
        }

        return $zone->copy([
            'status' => ShippingQuote::OK, 'message' => 'Ongkir dihitung dari tarif Everpro (zona '.$zone->zone.').', 'options' => $options,
            'zone' => null, 'source' => ShippingSettings::EVERPRO, 'sourceNote' => null, 'adminReason' => null,
        ]);
    }

    public const ADMIN_PLACE_NOT_FOUND = 'kota, kecamatan, atau kelurahan tujuan tidak ditemukan di Everpro';

    public const ADMIN_NO_ACCOUNT = 'akun Everpro belum aktif';

    public const ADMIN_NO_ORIGIN = 'lokasi asal Everpro belum diatur';

    /** What the AI is told when the address is not enough yet: ask, never guess an amount. */
    public const STRICT_UNCLEAR = 'Alamat tujuan belum cukup untuk menghitung ongkir dari Everpro. Tanyakan kecamatan dan kelurahan (atau kode pos) tujuan. Jangan menyebut nominal ongkir dulu.';

    /**
     * "Hanya pakai tarif Everpro" (ShippingSettings::everproStrict): the price comes from Everpro or not at all, the
     * zone table is never a price. Called by Shipping::quote instead of quote().
     *
     * - An address without a usable postal code, kelurahan or kecamatan: UNCLEAR_DESTINATION, ask for them.
     * - An address Everpro matches (the lead's place for certain, or its kecamatan and city with one postal code):
     *   Everpro's price, for the couriers asked, else the shop's first other courier served there.
     * - An address Everpro does not know, a province that differs, Everpro failing twice, no courier served:
     *   NEEDS_ADMIN with adminReason, so the AI hands the chat to CS and the inbox shows why.
     * A zone marked "konfirmasi admin" is returned as the zone quote, for forAdminZone() as before.
     */
    public function strict(ShippingRequest $request, ?Lead $lead): ShippingQuote
    {
        $zones = new ZoneShippingRates($this->settings);
        $zone = $zones->quote($request);
        $unclear = fn () => $zone->copy(['status' => ShippingQuote::UNCLEAR_DESTINATION, 'message' => self::STRICT_UNCLEAR, 'options' => [], 'zone' => null, 'source' => ShippingSettings::EVERPRO]);
        $admin = fn (string $why) => $zone->copy([
            'status' => ShippingQuote::NEEDS_ADMIN, 'message' => 'Ongkir ke tujuan ini perlu dikonfirmasi admin ('.$why.').', 'options' => [],
            'zone' => null, 'source' => ShippingSettings::EVERPRO, 'sourceNote' => null, 'adminReason' => $why,
        ]);

        if ($zone->status === ShippingQuote::UNCLEAR_DESTINATION) {
            // No city yet: ask. A city given that no region list knows: a person checks it.
            return blank($request->city) || $request->items === [] ? $zone->copy(['message' => self::STRICT_UNCLEAR]) : $admin(self::ADMIN_PLACE_NOT_FOUND);
        }

        if ($zone->status === ShippingQuote::NOT_AVAILABLE || $zones->confirmsAdmin($zone->zone)) {
            return $zone;
        }

        $account = Carriers::configured($this->tenant) ? Carriers::account($this->tenant) : null;

        if ($account === null || $account->provider !== 'everpro') {
            return $admin(self::ADMIN_NO_ACCOUNT);
        }

        if (($origin = Shipments::originPostal($account)) === null) {
            return $admin(self::ADMIN_NO_ORIGIN);
        }

        $provider = Carriers::make($account);

        if (method_exists($provider, 'withTimeout')) {
            $provider->withTimeout((int) config('shipping.everpro.timeout', 3));
        }

        try {
            $place = $this->strictPlace($provider, $request, $lead, $zone);
        } catch (Throwable $e) {
            Log::warning('Everpro location lookup for a strict quote failed', ['tenant' => $this->tenant?->getKey(), 'lead' => $lead?->getKey(), 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return $admin(self::ADMIN_EVERPRO_FAILED);
        }

        if ($place === 'unclear') {
            return $unclear();
        }

        if (is_string($place)) {
            return $admin($place);
        }

        [$postal, $subDistrict] = $place;
        $rates = null;

        // Once more when Everpro fails or is slow, then a person checks it.
        for ($try = 1; $try <= 2 && $rates === null; $try++) {
            try {
                $rates = $this->rates($provider, $account, $origin, $postal, $subDistrict, $zone);
            } catch (Throwable $e) {
                Log::warning('Everpro strict quote failed', ['tenant' => $this->tenant?->getKey(), 'lead' => $lead?->getKey(), 'try' => $try, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        if ($rates === null) {
            return $admin(self::ADMIN_EVERPRO_FAILED);
        }

        $options = $this->served($rates, $request->couriers ?? $this->settings->couriers());

        if ($options === [] && $request->couriers !== null) {
            $options = array_slice($this->served($rates, $this->settings->couriers()), 0, 1);
        }

        if ($options === []) {
            return $admin(self::ADMIN_NO_SERVICE);
        }

        return $zone->copy([
            'status' => ShippingQuote::OK, 'message' => 'Ongkir dihitung dari tarif Everpro.', 'options' => $options,
            'zone' => null, 'source' => ShippingSettings::EVERPRO, 'sourceNote' => null, 'adminReason' => null,
        ]);
    }

    /**
     * Where a strict quote prices to: [postal code, kelurahan], 'unclear' (ask the buyer for more), or why a person
     * must check it. The lead's own address uses its Everpro match; any other address is looked up by name.
     *
     * @return array{0: string, 1: ?string}|string
     */
    private function strictPlace(CarrierProvider $provider, ShippingRequest $request, ?Lead $lead, ShippingQuote $zone): array|string
    {
        $own = LeadQuote::isOwnCity($lead, $zone->city) ? LeadQuote::destination($lead) : null;

        if ($own !== null) {
            if ($lead->provider_location_state === null && ProviderLocations::key($lead) !== null) {
                app(ProviderLocations::class)->match($lead, provider: $provider);
                $lead->refresh();
            }

            $fresh = ProviderLocations::key($lead) === $lead->provider_location_key;
            $state = $fresh ? $lead->provider_location_state : null;

            if ($state === ProviderLocations::EXACT && ($place = ProviderLocations::place($lead)) !== null) {
                if (ProviderLocations::provinceDifference((array) $lead->shipping_address_parts + ['provinsi' => $own['provinsi']], $place) !== null) {
                    return 'provinsi tujuan berbeda dengan data Everpro';
                }

                $postal = $place->postalCode ?: ($own['kode_pos'] ?? null);

                return $postal !== null ? [$postal, $place->subDistrict ?: null] : self::ADMIN_PLACE_UNCERTAIN;
            }

            if ($state === ProviderLocations::MULTIPLE) {
                return $this->inDistrict(ProviderLocations::candidates($lead), $own['kecamatan'] ?? $request->district, $own['kota'], $own['kode_pos'] ?? $request->postalCode);
            }

            if ($state === ProviderLocations::NONE) {
                return str_contains((string) $lead->provider_location_note, 'belum ada kode pos atau kelurahan') ? 'unclear' : self::ADMIN_PLACE_NOT_FOUND;
            }
        }

        if ($request->postalCode === null && blank($request->subDistrict) && blank($request->district)) {
            return 'unclear';
        }

        if ($request->postalCode !== null) {
            return [$request->postalCode, $request->subDistrict];
        }

        $places = $provider->locations(null, implode(', ', array_filter([$request->subDistrict, $request->district, $request->city], fn ($v) => filled($v))));

        if (filled($request->subDistrict)) {
            $norm = ProviderLocations::norm(...);
            $found = array_values(array_filter($places, fn (CarrierLocation $l) => $norm($l->subDistrict) === $norm($request->subDistrict) && filled($l->postalCode)
                && (blank($request->district) || $l->district === null || $norm($l->district) === $norm($request->district))));

            if (count($found) === 1) {
                return [$found[0]->postalCode, $found[0]->subDistrict];
            }

            if (count($found) > 1 || blank($request->district)) {
                return count($found) > 1 ? 'unclear' : self::ADMIN_PLACE_NOT_FOUND;
            }
        }

        return $this->inDistrict($places, $request->district, (string) $request->city, null);
    }

    /**
     * The kelurahan is not certain: priced when the kecamatan and city match places of Everpro's list that share one
     * postal code (or the buyer's own postal code is one of theirs). Several postal codes: ask for the kelurahan.
     *
     * @param  list<CarrierLocation>  $places
     * @return array{0: string, 1: null}|string
     */
    private function inDistrict(array $places, ?string $district, string $city, ?string $postal): array|string
    {
        $norm = ProviderLocations::norm(...);

        if (blank($district)) {
            return 'unclear';
        }

        $inKec = array_values(array_filter($places, fn (CarrierLocation $l) => $l->district !== null && $norm($l->district) === $norm($district)
            && ($l->city === null || $norm(Regions::city($l->city)) === $norm(Regions::city($city))) && filled($l->postalCode)));

        if ($inKec === []) {
            return self::ADMIN_PLACE_NOT_FOUND;
        }

        $codes = array_values(array_unique(array_map(fn (CarrierLocation $l) => (string) $l->postalCode, $inKec)));

        if ($postal !== null && in_array($postal, $codes, true)) {
            return [$postal, null];
        }

        return count($codes) === 1 ? [$codes[0], null] : 'unclear';
    }

    /**
     * The lead's Everpro place when it is certain (state exact, for the current address) and the quote is for the
     * lead's own city. A lead not matched yet is matched now.
     */
    private function leadPlace(?Lead $lead, ShippingQuote $zone): ?CarrierLocation
    {
        $own = $lead !== null ? LeadQuote::destination($lead) : null;
        if ($own === null || blank($zone->city)) {
            return null;
        }

        [$a, $b] = [RegionDirectory::matchCity($own['kota'])['name'] ?? null, RegionDirectory::matchCity((string) $zone->city)['name'] ?? null];

        if ($a !== null && $b !== null ? $a !== $b : Regions::city($own['kota']) !== Regions::city((string) $zone->city)) {
            return null;
        }

        if ($lead->provider_location_state === null && ProviderLocations::key($lead) !== null) {
            try {
                app(ProviderLocations::class)->match($lead);
                $lead->refresh();
            } catch (Throwable $e) {
                Log::warning('Provider location match during a quote failed', ['tenant' => $this->tenant?->getKey(), 'lead' => $lead->getKey(), 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return ProviderLocations::place($lead);
    }

    /**
     * @param  list<CarrierRate>  $rates
     * @param  list<string>  $couriers
     * @return list<ShippingOption>
     */
    private function served(array $rates, array $couriers): array
    {
        $options = [];

        foreach ($couriers as $courier) {
            $rate = collect($rates)->first(fn (CarrierRate $r) => $r->category() === 'regular' && $r->matches($courier))
                ?? collect($rates)->first(fn (CarrierRate $r) => $r->matches($courier));

            if ($rate !== null) {
                $options[] = new ShippingOption($courier, $rate->price, $rate->etaMin, $rate->etaMax);
            }
        }

        return $options;
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
