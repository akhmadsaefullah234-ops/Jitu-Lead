<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\CloudApiProvider;
use App\WhatsApp\MediaStore;
use App\WhatsApp\Providers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Downloads a picture that arrived through the official API, where the webhook only carries a media id. */
class FetchWhatsAppMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public int $messageId)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CurrentTenant $current, Providers $providers, MediaStore $store): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () use ($providers, $store) {
            $message = WaMessage::query()->with('channel')->find($this->messageId);
            $id = $message?->media['id'] ?? null;

            if ($message === null || $id === null || isset($message->media['path']) || $message->channel === null) {
                return;
            }

            $provider = $providers->for($message->channel);
            $bytes = $provider instanceof CloudApiProvider ? $provider->downloadMedia((string) $id) : null;

            if ($bytes === null) {
                if ($this->attempts() >= $this->tries) {
                    $store->fail($message, 'download_failed');

                    return;
                }

                $this->release(60);

                return;
            }

            $store->storeImage($message, $bytes);
        });
    }
}
