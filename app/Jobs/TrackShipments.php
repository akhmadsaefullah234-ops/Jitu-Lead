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

            $open = $all->reject(fn (Shipment $s) => $s->manual())->keyBy(fn (Shipment $s) => $s->awb);

            if ($open->isEmpty()) {
                return;
            }

            try {
                $results = Carriers::for($tenant)->trackMany($open->keys()->all());
            } catch (CarrierException $e) {
                Log::warning('Shipment tracking failed', ['tenant' => $tenant->getKey(), 'reason' => $e->getMessage()]);

                return;
            }

            foreach ($results as $awb => $result) {
                if ($shipment = $open->get($awb)) {
                    $shipments->apply($shipment, $result);
                }
            }
        });
    }
}
