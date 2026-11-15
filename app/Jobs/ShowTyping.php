<?php

namespace App\Jobs;

use App\Enums\WaChannelType;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Mengetik..." in a client's chat while an automatic reply waits its pause. Only a QR gateway can show it. */
class ShowTyping implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public int $tenantId, public int $channelId, public string $phone) {}

    public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        $current->run($tenant, function () {
            $channel = WaChannel::query()->find($this->channelId);

            if ($channel?->type === WaChannelType::Gateway) {
                (new GatewayProvider($channel))->typing($this->phone);
            }
        });
    }
}
