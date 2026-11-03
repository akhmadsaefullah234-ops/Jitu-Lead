<?php

namespace App\Jobs;

use App\Models\ConversionEvent;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Tracking\BusinessMessagingConversions;
use App\Tracking\ConversionPayload;
use App\Tracking\MetaConversions;
use App\Tracking\TikTokEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports a conversion (a new web lead, or a lead's progress in the CRM) to the ad platforms the agency
 * connected. Each platform is tried on its own so one outage does not hide the other; a retry skips the ones
 * that already took a CRM event. The last answer of each platform is noted for the Pelacakan page, and a sent
 * CRM event is written on the lead. Neither the log nor the lead ever holds a token or customer data.
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

    public function handle(CurrentTenant $current, MetaConversions $meta, TikTokEvents $tiktok, ?BusinessMessagingConversions $messaging = null): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $failure = null;
        $messaging ??= app(BusinessMessagingConversions::class);

        $current->run($tenant, function () use ($meta, $tiktok, $messaging, &$failure) {
            $settings = TrackingSetting::active();

            if ($settings === null) {
                return;
            }

            $payload = ConversionPayload::fromArray($this->payload);
            $record = isset($payload->extra['record']) ? ConversionEvent::query()->find($payload->extra['record']) : null;
            $results = (array) ($record?->results ?? []);

            foreach (['meta' => $meta, 'tiktok' => $tiktok, 'meta_messaging' => $messaging] as $name => $sender) {
                if (($results[$name]['ok'] ?? false) === true) {
                    continue;
                }

                $event = $name === 'meta_messaging' ? ($payload->extra['messaging']['event'] ?? null) : $payload->eventName($name, $settings->eventFor($name));

                try {
                    if (! $sender->send($settings, $payload)) {
                        continue;
                    }

                    $results[$name] = ['ok' => true, 'at' => now()->toIso8601String()];
                    $settings->remember($name, true, $event);
                    $this->noteOnLead($payload, $event, $name);
                } catch (Throwable $e) {
                    $status = $e instanceof RequestException ? $e->response->status() : null;
                    // Log the platform and status only; the request carries tokens and hashed customer data.
                    Log::warning("Conversion event to {$name} failed", ['tenant' => $this->tenantId, 'event' => $event, 'status' => $status]);
                    $results[$name] = ['ok' => false, 'at' => now()->toIso8601String(), 'status' => $status];
                    $settings->remember($name, false, $event, $status);
                    $failure = $e;
                }
            }

            $record?->forceFill(['results' => $results])->save();
        });

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** "Event Purchase dikirim ke Meta" on the lead the event was about. */
    private function noteOnLead(ConversionPayload $payload, ?string $event, string $destination): void
    {
        $lead = isset($payload->extra['lead_id']) ? Lead::query()->find($payload->extra['lead_id']) : null;

        $lead?->activities()->create([
            'type' => 'note',
            'body' => "Event {$event} dikirim ke ".TrackingSetting::DESTINATIONS[$destination],
            'meta' => ['conversion' => $event, 'destination' => $destination, 'event_id' => $payload->eventId],
        ]);
    }
}
