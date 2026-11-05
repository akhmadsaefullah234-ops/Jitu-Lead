<?php

namespace App\Console\Commands;

use App\Models\ShipmentNotice;
use App\Models\Tenant;
use App\Shipping\Shipments\StatusNotices;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shipping:notices')]
#[Description('Kirim pesan status kiriman yang ditunda jam tenang')]
class SendShipmentNoticesCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        ShipmentNotice::query()->withoutGlobalScopes()->where('state', 'sending')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['state' => ShipmentNotice::PENDING, 'updated_at' => now()]);

        ShipmentNotice::query()->withoutGlobalScopes()->where('state', ShipmentNotice::PENDING)->where('due_at', '<=', now())
            ->orderBy('due_at')->limit(500)->get()->groupBy('tenant_id')
            ->each(function ($notices, $tenantId) use ($current) {
                if ($tenant = Tenant::query()->find($tenantId)) {
                    $current->run($tenant, fn () => $notices->each(function (ShipmentNotice $n) {
                        try {
                            app(StatusNotices::class)->deliver($n);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }));
                }
            });

        return self::SUCCESS;
    }
}
