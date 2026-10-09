<?php

namespace App\Tracking;

use App\Models\TrackingSetting;
use Illuminate\Support\Facades\Http;

/**
 * Meta Conversions API: sends the same "Lead" event the browser pixel fired,
 * with the same event_id so Meta counts it once.
 */
class MetaConversions
{
    public function send(TrackingSetting $settings, ConversionPayload $payload): void
    {
        $token = $settings->credential('meta_capi_token');

        if (blank($settings->meta_pixel_id) || blank($token)) {
            return;
        }

        $user = array_filter([
            'em' => $payload->emailHash ? [$payload->emailHash] : null,
            'ph' => $payload->phoneHash ? [$payload->phoneHash] : null,
            'client_ip_address' => $payload->ip,
            'client_user_agent' => $payload->userAgent,
            'fbp' => $payload->ids['fbp'] ?? null,
            'fbc' => $payload->ids['fbc'] ?? null,
        ]);

        $body = array_filter([
            'data' => [array_filter([
                'event_name' => 'Lead',
                'event_time' => $payload->eventTime,
                'event_id' => $payload->eventId,
                'action_source' => 'website',
                'event_source_url' => $payload->sourceUrl,
                'user_data' => $user,
            ])],
            'test_event_code' => $settings->credential('meta_test_event_code'),
        ]);

        Http::timeout(10)->acceptJson()->asJson()
            ->withToken($token)
            ->post(rtrim(config('services.meta.graph_url'), '/').'/'.config('services.meta.graph_version').'/'.$settings->meta_pixel_id.'/events', $body)
            ->throw();
    }
}
