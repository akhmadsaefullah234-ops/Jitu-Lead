<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Models\Tenant;
use App\Shipping\Shipments\LabelStore;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Keeps a parcel's label PDF once its tracking number comes (App\Shipping\Shipments\LabelStore). The provider may
 * not have the label straight away: tried again later, at most config('carriers.label_tries') times, after which
 * the parcel shows "Label belum tersedia" under "Perlu tindakan" in the Pengiriman menu.
 */
class SaveShipmentLabel implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 60;

    public function __construct(public int $shipmentId, public int $tenantId)
    {
        $this->tries = max(1, (int) config('carriers.label_tries', 5));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () {
            $shipment = Shipment::query()->find($this->shipmentId);

            if ($shipment === null || ! $shipment->printable() || filled($shipment->label_path)) {
                return;
            }

            if (app(LabelStore::class)->save($shipment)) {
                return;
            }

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 3600);

                return;
            }

            $shipment->forceFill(['label_failed_at' => now()])->save();
        });
    }
}
