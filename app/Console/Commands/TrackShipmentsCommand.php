<?php

namespace App\Console\Commands;

use App\Jobs\TrackShipments;
use App\Models\Shipment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shipping:track')]
#[Description('Lacak ulang paket yang belum sampai (cadangan bila callback penyedia terlewat)')]
class TrackShipmentsCommand extends Command
{
    public function handle(): int
    {
        $tenants = Shipment::query()->withoutGlobalScopes()->where('state', Shipment::SHIPPED)->whereNull('final_at')->whereNotNull('awb')
            ->distinct()->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            TrackShipments::dispatch((int) $tenantId);
        }

        return self::SUCCESS;
    }
}
