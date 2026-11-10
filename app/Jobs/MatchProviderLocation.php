<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\Tenant;
use App\Shipping\Shipments\ProviderLocations;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Matches one lead's address to the shipping provider's place (ProviderLocations), after the address changed. */
class MatchProviderLocation implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public int $leadId) {}

    public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () {
            if ($lead = Lead::query()->find($this->leadId)) {
                app(ProviderLocations::class)->match($lead);
            }
        });
    }
}
