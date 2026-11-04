<?php

namespace App\Jobs;

use App\Actions\HandleInboundWhatsApp;
use App\Models\Tenant;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\CloudApiProvider;
use App\WhatsApp\GatewayProvider;
use App\WhatsApp\MediaStore;
use App\WhatsApp\Providers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Downloads a picture, voice note, sticker or video the webhook only named: the official API always sends a
 * media id, a gateway does so for a file too big for its webhook. A voice note is answered by the AI once
 * its file is in (or could not be had).
 */
class FetchWhatsAppMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public int $messageId)
    {
        $this->afterCommit();
    }

    private function answer(WaMessage $message): void
    {
        if ($message->type === 'audio' && HandleInboundWhatsApp::aiAnswers($message->channel, $message)) {
            GenerateAiReplyJob::dispatch($this->tenantId, $message->getKey());
        }
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
            $bytes = $provider instanceof CloudApiProvider || $provider instanceof GatewayProvider ? $provider->downloadMedia((string) $id) : null;

            if ($bytes === null) {
                if ($this->attempts() >= $this->tries) {
                    $store->fail($message, 'download_failed');
                    $this->answer($message);

                    return;
                }

                $this->release(60);

                return;
            }

            $store->store($message, $bytes);
            $this->answer($message);
        });
    }
}
