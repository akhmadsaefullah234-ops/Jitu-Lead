<?php

namespace App\Console\Commands;

use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Tenant;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\ShippingSummary;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('shipping:auto-resi')]
#[Description('Buat resi otomatis untuk lead yang jedanya sudah lewat, dan kirim ringkasan pengiriman harian ke admin')]
class AutoResiCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        $due = Lead::query()->withoutGlobalScopes()->where('auto_shipment_state', AutoShipments::SCHEDULED)->where('auto_shipment_at', '<=', now())
            ->distinct()->pluck('tenant_id');
        $made = 0;

        foreach (Tenant::query()->whereIn('id', $due)->get() as $tenant) {
            try {
                $made += $current->run($tenant, fn () => app(AutoShipments::class)->due());
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Once a day, in each agency's morning: yesterday's automatic resi and what still needs doing.
        foreach (Tenant::query()->whereIn('id', CarrierAccount::query()->withoutGlobalScopes()->pluck('tenant_id'))->get() as $tenant) {
            try {
                $current->run($tenant, fn () => app(ShippingSummary::class)->sendIfDue($tenant));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("{$made} resi otomatis dibuat.");

        return self::SUCCESS;
    }
}
