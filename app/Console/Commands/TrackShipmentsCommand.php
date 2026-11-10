<?php

namespace App\Console\Commands;

use App\Jobs\TrackShipments;
use App\Models\Shipment;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The scheduled tracking (every 2 hours) queues one job per agency. With --now it runs here and says what came
 * back: run it once after a deploy, or whenever the Pengiriman menu shows "belum diperbarui > 6 jam".
 */
#[Signature('shipping:track {--now : Lacak sekarang (tanpa antrean) dan tampilkan hasilnya} {--tenant= : Hanya agensi ini (id atau slug)}')]
#[Description('Lacak ulang paket yang belum sampai (cadangan bila callback penyedia terlewat)')]
class TrackShipmentsCommand extends Command
{
    public function handle(): int
    {
        $tenants = Shipment::query()->withoutGlobalScopes()->where('state', Shipment::SHIPPED)->whereNull('final_at')->whereNotNull('awb')->whereNull('untrackable_at')
            ->when($this->option('tenant'), function ($q, $t) {
                $tenant = Tenant::query()->where(fn ($q) => $q->where('slug', $t)->when(ctype_digit((string) $t), fn ($q) => $q->orWhere('id', (int) $t)))->first();
                $q->where('tenant_id', $tenant?->getKey() ?? 0);
            })
            ->distinct()->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            if (! $this->option('now')) {
                TrackShipments::dispatch((int) $tenantId);

                continue;
            }

            $job = new TrackShipments((int) $tenantId);
            app()->call([$job, 'handle']);
            $name = Tenant::query()->whereKey($tenantId)->value('name') ?? '#'.$tenantId;
            $this->line($name.': '.$job->tracked.' kiriman diperbarui'.($job->rejected !== [] ? ', ditolak penyedia: '.implode(', ', $job->rejected) : '').'.');
        }

        if ($this->option('now') && $tenants->isEmpty()) {
            $this->line('Tidak ada kiriman yang perlu dilacak.');
        }

        return self::SUCCESS;
    }
}
