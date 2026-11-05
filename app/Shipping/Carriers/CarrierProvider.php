<?php

namespace App\Shipping\Carriers;

use Illuminate\Http\Request;

/**
 * A courier aggregator that books parcels (Everpro first; KiriminAja or Biteship later). Everything else
 * (Buat resi, labels, status messages, the timeline, automatic stages, the AI) only talks to this, through
 * App\Shipping\Carriers\Carriers. Every method throws CarrierException with a message an agent can act on.
 */
interface CarrierProvider
{
    /** The provider key, as in config/carriers.php. */
    public function key(): string;

    /** Available balance in rupiah, or null when the provider does not say. */
    public function balance(): ?int;

    /** @return list<CarrierLocation> places matching a postal code and/or written area ("Coblong, Bandung") */
    public function locations(?string $postalCode, ?string $area = null): array;

    /** @return list<CarrierRate> */
    public function rates(RateRequest $request): array;

    /** @return list<string> pickup times for a rate, as ISO 8601 strings, soonest first */
    public function pickupTimes(string $rateCode): array;

    /** Books the parcel. Idempotent on $order->clientOrderNo: booking the same number twice returns the first. */
    public function createOrder(OrderRequest $order): CarrierOrder;

    /** Asks for the tracking number; null while the courier has not issued one yet. */
    public function generateAwb(string $clientOrderNo): ?string;

    /** URL of the parcel label (PDF). */
    public function label(string $awb): string;

    public function track(string $awb): TrackResult;

    /**
     * @param  list<string>  $awbs
     * @return array<string, TrackResult> by tracking number; parcels the provider did not return are left out
     */
    public function trackMany(array $awbs): array;

    public function cancel(string $clientOrderNo, string $reason): void;

    /** The provider's callback as an event; null when it carries nothing we use. Verify the request first with verifyCallback(). */
    public function parseCallback(Request $request): ?CallbackEvent;

    /** True when the callback really comes from the provider for this account. */
    public function verifyCallback(Request $request): bool;
}
