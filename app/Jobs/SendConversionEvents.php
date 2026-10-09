<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Tracking\ConversionPayload;
use App\Tracking\MetaConversions;
use App\Tracking\TikTokEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports a new web lead to the ad platforms the agency connected. Each
 * platform is tried on its own so one outage does not hide the other.
 */
class SendConversionEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public int $tenantId, public array $payload)
    {
        $this->backoff = [30, 120];
    }

    public function handle(CurrentTenant $current, MetaConversions $meta, TikTokEvents $tiktok): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $failure = null;

        $current->run($tenant, function () use ($meta, $tiktok, &$failure) {
            $settings = TrackingSetting::current();

            if ($settings === null) {
                return;
            }

            $payload = ConversionPayload::fromArray($this->payload);

            foreach (['meta' => $meta, 'tiktok' => $tiktok] as $name => $sender) {
                try {
                    $sender->send($settings, $payload);
                } catch (Throwable $e) {
                    // Log the platform and status only; the request carries tokens.
                    Log::warning("Conversion event to {$name} failed", ['tenant' => $this->tenantId, 'status' => method_exists($e, 'getCode') ? $e->getCode() : null]);
                    $failure = $e;
                }
            }
        });

        if ($failure !== null) {
            throw $failure;
        }
    }
}
