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
 * Asks the provider for the tracking number of a booked parcel that had none yet: every EVERY_MINUTES minutes,
 * TRIES times (half an hour), unless the provider's callback brings it first. Then the parcel shows "Nomor resi
 * belum terbit" under "Perlu tindakan" and the agent is told.
 */
class FetchShipmentAwb implements ShouldQueue
{
    use Queueable;

    public const EVERY_MINUTES = 2;

    public const TRIES = 15;

    public int $tries = 1;

    public function __construct(public int $shipmentId, public int $tenantId) {}

    public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () {
            $shipment = Shipment::query()->with('lead')->find($this->shipmentId);

            if ($shipment === null || $shipment->state !== Shipment::ORDERED || filled($shipment->awb) || filled($shipment->error)) {
                return;
            }

            $tries = (int) data_get($shipment->request, 'awb_tries', 0) + 1;
            $shipment->forceFill(['request' => ['awb_tries' => $tries] + (array) $shipment->request])->save();
            $shipments = app(Shipments::class);

            if ($tries > self::TRIES) {
                $shipments->awbLate($shipment);

                return;
            }

            try {
                $shipments->place($shipment);
            } catch (CarrierException $e) {
                if (! $e->retryable()) {
                    $shipments->failed($shipment, $e);

                    return;
                }

                self::dispatch($this->shipmentId, $this->tenantId)->delay(now()->addMinutes(self::EVERY_MINUTES));
            }
        });
    }
}
