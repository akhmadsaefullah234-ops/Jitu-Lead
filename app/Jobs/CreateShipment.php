<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Models\Tenant;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Books one parcel with the provider and fetches its tracking number (App\Shipping\Shipments\Shipments::place).
 * A provider that is down is tried again a few times; one that refuses (balance, address) is not, and the
 * agent is told why. The order number is the same every time, so a retry never books twice.
 */
class CreateShipment implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 120;

    public function __construct(public int $shipmentId, public int $tenantId)
    {
        $this->tries = max(1, (int) config('carriers.tries', 3));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () {
            $shipment = Shipment::query()->with('lead')->find($this->shipmentId);

            if ($shipment === null || $shipment->lead === null) {
                return;
            }

            $shipments = app(Shipments::class);

            try {
                $shipments->place($shipment);
            } catch (CarrierException $e) {
                if ($e->retryable() && $this->attempts() < $this->tries) {
                    $shipment->forceFill(['error' => mb_substr($e->getMessage(), 0, 250)])->save();
                    $this->release($this->backoff()[$this->attempts() - 1] ?? 600);

                    return;
                }

                $shipments->failed($shipment, $e);
            }
        });
    }
}
