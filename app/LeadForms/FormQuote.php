<?php

namespace App\LeadForms;

use App\Models\LandingPage;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Regions\RegionDirectory;
use App\Shipping\Regions;
use App\Shipping\Shipping;
use App\Shipping\ShippingQuote;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The order summary a shop's form shows above its send button: price x quantity, shipping (with the product's
 * shipping promo) and the total, for the address picked so far. Priced like the AI's cek ongkir (Shipping::quote),
 * with the form's product, else the landing page's. Runs inside the tenant.
 */
class FormQuote
{
    public const KEY_PREFIX = 'form-quote.v2.';

    public const CACHE_SECONDS = 600;

    /** The product a form sells, when it has a price and the shop prices shipping; otherwise no summary. */
    public static function product(LeadForm $form, ?int $pageId = null): ?Property
    {
        $product = $form->property_id ? Property::query()->find($form->property_id) : null;
        $product ??= $pageId ? LandingPage::query()->find($pageId)?->property : null;

        return $product !== null && $product->price_from !== null ? $product : null;
    }

    /**
     * Whether the form shows the summary (and its page may ask /ringkasan): it can (eligible()) and the shop has
     * not turned "Tampilkan rincian harga dan ongkir" off. Read by the form page, landing-page blocks and iframes
     * alike (FormView::block) and by the endpoint. The AI's draft order summary never comes here: it prices
     * through App\Ai\LeadQuote from what the lead holds, so the switch changes nothing the AI sends.
     */
    public static function available(Tenant $tenant, LeadForm $form, ?int $pageId = null): bool
    {
        return $form->showsSummary() && self::eligible($tenant, $form, $pageId);
    }

    /** Whether the form could show a summary at all: the shop prices shipping, a priced product, an address for shipping. */
    public static function eligible(Tenant $tenant, LeadForm $form, ?int $pageId = null): bool
    {
        return Shipping::available($tenant) && self::addressField($form) !== null && self::product($form, $pageId) !== null;
    }

    /**
     * For the editor: whether the summary can show anywhere the form is used, on its own page or inside a landing
     * page whose product has a price (the main form fills in on any page).
     */
    public static function eligibleAnywhere(Tenant $tenant, LeadForm $form): bool
    {
        if (! Shipping::available($tenant) || self::addressField($form) === null) {
            return false;
        }

        if (self::product($form) !== null) {
            return true;
        }

        return LandingPage::query()->whereHas('property', fn ($q) => $q->whereNotNull('price_from'))->get()
            ->contains(fn (LandingPage $page) => $form->is_primary
                || collect($page->sections())->contains(fn ($s) => ($s['type'] ?? null) === 'form' && (int) ($s['props']['form_id'] ?? 0) === (int) $form->getKey()));
    }

    /** @return array<string, mixed>|null the address field whose answer is the shipping address */
    private static function addressField(LeadForm $form): ?array
    {
        return collect($form->fieldList())->first(fn ($f) => $f['type'] === 'address' && ($f['maps_to'] ?? null) === 'shipping_address');
    }

    /**
     * @param  array<string, mixed>  $address  the address answer as the form sends it (codes for dropdowns)
     * @return array<string, mixed> {ok, harga, jumlah, subtotal, ongkir?, ongkir_normal?, promo?, kurir?, total?, pesan?} with *_teks
     */
    public static function for(Tenant $tenant, LeadForm $form, array $address, int $quantity, ?int $pageId = null): array
    {
        $product = self::product($form, $pageId);
        $field = self::addressField($form);

        // Turned off ("Tampilkan rincian harga dan ongkir"): nothing is priced, the page draws no box.
        if (! $form->showsSummary() || $product === null || $field === null || ! Shipping::available($tenant)) {
            return ['ok' => false];
        }

        $quantity = max(1, min(1000, $quantity));
        $subtotal = (int) $product->price_from * $quantity;
        $out = ['ok' => true, 'harga' => (int) $product->price_from, 'jumlah' => $quantity, 'subtotal' => $subtotal];

        $parts = FormFields::addressValues($field, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $address));
        $city = trim((string) ($parts['kota'] ?? ''));

        if ($city === '') {
            return $out + ['pesan' => 'Ongkir dihitung setelah kota/kabupaten dipilih.'];
        }

        $province = isset($parts['codes']['provinsi']) ? RegionDirectory::shippingProvince((string) $parts['provinsi'])
            : (Regions::province((string) ($parts['provinsi'] ?? '')) ?? Regions::provinceOfCity($city));

        // v2: the entry is ShippingQuote::toData(). Entries before it held the object, which the cache cannot read back.
        $key = self::KEY_PREFIX.$tenant->getKey().'.'.$form->getKey().'.'.$product->getKey().'.'.$form->updated_at?->timestamp.'.'
            .md5(json_encode([$city, $province, $parts['kecamatan'] ?? null, $parts['kelurahan'] ?? null, $parts['kode_pos'] ?? null, $quantity]));

        try {
            $quote = self::cached($key, fn () => Shipping::quote($tenant, $city, $province, [['product_id' => $product->getKey(), 'jumlah' => $quantity]],
                filled($parts['kecamatan'] ?? null) ? (string) $parts['kecamatan'] : null, null,
                filled($parts['kode_pos'] ?? null) ? (string) $parts['kode_pos'] : null, filled($parts['kelurahan'] ?? null) ? (string) $parts['kelurahan'] : null));
        } catch (Throwable $e) {
            report($e);

            return $out + ['pesan' => 'Ongkir akan dikonfirmasi lewat WhatsApp.'];
        }

        $option = $quote->ok() ? ($quote->primaryCourier !== null ? $quote->option($quote->primaryCourier) : null) ?? ($quote->options[0] ?? null) : null;

        if ($option === null) {
            return $out + ['pesan' => 'Ongkir akan dikonfirmasi lewat WhatsApp.'];
        }

        return $out + array_filter([
            'kurir' => $option->courier,
            'ongkir' => $option->price,
            'ongkir_normal' => $option->discount() > 0 ? $option->normalPrice : null,
            'promo' => $option->discount() > 0 ? $option->promo : null,
            'total' => $subtotal + $option->price,
        ], fn ($v) => $v !== null);
    }

    /**
     * The quote kept for 10 minutes as plain data (ShippingQuote::toData), never as an object: the cache reads no
     * classes back (cache.serializable_classes is false, against gadget chains). An entry that is not valid data
     * (an object from before v2, or anything fromData refuses) counts as a miss: it is dropped and priced again.
     */
    private static function cached(string $key, Closure $quote): ShippingQuote
    {
        $data = Cache::get($key);

        if ($data !== null) {
            try {
                if (is_array($data)) {
                    return ShippingQuote::fromData($data);
                }
            } catch (Throwable) {
                // not valid data: priced again below
            }

            Cache::forget($key);
        }

        $fresh = $quote();
        Cache::put($key, $fresh->toData(), self::CACHE_SECONDS);

        return $fresh;
    }

    /** The same with the amounts written out ("Rp 150.000"), for the page. */
    public static function withText(array $quote): array
    {
        foreach (['harga', 'subtotal', 'ongkir', 'ongkir_normal', 'total'] as $k) {
            if (isset($quote[$k])) {
                $quote[$k.'_teks'] = ShippingQuote::rupiah((int) $quote[$k]);
            }
        }

        return $quote;
    }
}
