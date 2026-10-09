<?php

namespace App\Tracking;

use App\Models\TrackingSetting;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Events API: the server-side twin of the browser pixel's form event,
 * deduplicated by event_id.
 */
class TikTokEvents
{
    public function send(TrackingSetting $settings, ConversionPayload $payload): void
    {
        $token = $settings->credential('tiktok_events_token');

        if (blank($settings->tiktok_pixel_id) || blank($token)) {
            return;
        }

        $user = array_filter([
            'email' => $payload->emailHash,
            'phone' => $payload->phoneHash,
            'ip' => $payload->ip,
            'user_agent' => $payload->userAgent,
            'ttclid' => $payload->ids['ttclid'] ?? null,
            'ttp' => $payload->ids['ttp'] ?? null,
        ]);

        $body = array_filter([
            'event_source' => 'web',
            'event_source_id' => $settings->tiktok_pixel_id,
            'test_event_code' => $settings->credential('tiktok_test_event_code'),
            'data' => [array_filter([
                'event' => 'SubmitForm',
                'event_time' => $payload->eventTime,
                'event_id' => $payload->eventId,
                'user' => $user,
                'page' => $payload->sourceUrl ? ['url' => $payload->sourceUrl] : null,
            ])],
        ]);

        Http::timeout(10)->acceptJson()->asJson()
            ->withHeaders(['Access-Token' => $token])
            ->post(config('services.tiktok.events_url'), $body)
            ->throw();
    }
}
