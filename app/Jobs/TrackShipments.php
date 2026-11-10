<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Models\Tenant;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tracks an agency's parcels that are still on their way, in one batch call, and applies what changed: the same
 * status messages and stage moves as a callback, so a missed callback costs nothing but time. Numbers linked from
 * outside JITU LEAD (source manual) are tracked one by one and stop after three answers that do not know them.
 */
class TrackShipments implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** After handle(): parcels whose status came back, and numbers the provider refused (for shipping:track --now). */
    public int $tracked = 0;

    /** @var list<string> */
    public array $rejected = [];

    public function __construct(public int $tenantId) {}

    public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || ! Carriers::configured($tenant)) {
            return;
        }

        $current->run($tenant, function () use ($tenant) {
            $shipments = app(Shipments::class);
            $all = Shipments::unfinished()->get();

            // Numbers linked from outside JITU LEAD are tracked one by one: a provider that does not know one says so.
            foreach ($all->filter(fn (Shipment $s) => $s->manual()) as $linked) {
                try {
                    $shipments->trackLinked($linked);
                } catch (CarrierException $e) {
                    Log::warning('Linked shipment tracking failed', ['tenant' => $tenant->getKey(), 'shipment' => $linked->getKey(), 'reason' => $e->getMessage()]);
                }
            }

            // Keyed "awb:<number>": a digits-only number would otherwise become an integer key and go out as a JSON number.
            $open = [];

            foreach ($all->reject(fn (Shipment $s) => $s->manual()) as $shipment) {
                $open['awb:'.strtoupper((string) $shipment->awb)] ??= $shipment;
            }

            if ($open === []) {
                return;
            }

            $provider = Carriers::for($tenant);
            $awbs = array_values(array_map(fn (Shipment $s) => (string) $s->awb, $open));

            try {
                $results = $provider->trackMany($awbs);
            } catch (CarrierException $e) {
                Log::warning('Shipment tracking failed', ['tenant' => $tenant->getKey(), 'reason' => $e->getMessage(), 'parcels' => count($awbs)]);

                return;
            }

            foreach ($results as $result) {
                if ($shipment = $open['awb:'.strtoupper((string) $result->awb)] ?? null) {
                    $shipments->apply($shipment, $result);
                    $this->tracked++;
                }
            }

            $this->rejected = array_map('strval', array_keys((array) ($provider->rejected ?? [])));
        });
    }
}
