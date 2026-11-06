<?php

namespace App\Console\Commands;

use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every 10 minutes: agencies with parcels "Menunggu saldo" have their balance read, and the parcels it covers are sent
 * again (never a second order). The balance of every other connected account is read again at most hourly, for the
 * low-balance warning in the Pengiriman menu, the settings and the daily report.
 */
#[Signature('shipping:balance-wait')]
#[Description('Cek saldo penyedia untuk kiriman yang menunggu saldo dan coba lagi yang sudah tertutup saldo')]
class BalanceWaitCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        $waiting = Shipment::query()->withoutGlobalScopes()->whereNotNull('balance_wait_at')->whereIn('state', [Shipment::PENDING, Shipment::ORDERED])
            ->whereNull('awb')->distinct()->pluck('tenant_id');
        $stale = CarrierAccount::query()->withoutGlobalScopes()->where(fn ($q) => $q->whereNull('balance_at')->orWhere('balance_at', '<', now()->subHour()))
            ->pluck('tenant_id');
        $resumed = 0;

        foreach (Tenant::query()->whereIn('id', $waiting->merge($stale)->unique())->get() as $tenant) {
            try {
                $resumed += $current->run($tenant, function () use ($tenant, $waiting) {
                    $shipments = app(Shipments::class);

                    if ($waiting->contains($tenant->getKey())) {
                        return $shipments->resumeWaiting();
                    }

                    if (($account = $shipments->account()) !== null && $account->hasCredentials()) {
                        $shipments->checkBalance($account, Carriers::make($account));
                    }

                    return 0;
                });
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("{$resumed} kiriman dicoba lagi setelah saldo cukup.");

        return self::SUCCESS;
    }
}
