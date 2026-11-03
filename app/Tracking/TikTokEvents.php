<?php

namespace App\Tracking;

use App\Models\TrackingSetting;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Events API: the server-side twin of the browser pixel's form event, deduplicated by event_id, and the
 * events a lead's progress in the CRM sends (App\Tracking\PipelineConversions), in TikTok's names.
 */
class TikTokEvents
{
    /** @return bool whether an event was sent (false: not set up, or nothing to send) */
    public function send(TrackingSetting $settings, ConversionPayload $payload): bool
    {
        $token = $settings->credential('tiktok_events_token');
        $event = $payload->eventName('tiktok', $settings->eventFor('tiktok'));
        $testCode = $settings->credential('tiktok_test_event_code');

        if (blank($settings->tiktok_pixel_id) || blank($token) || $event === null || ($payload->isTest() && blank($testCode))) {
            return false;
        }

        $user = array_filter([
            'email' => $payload->emailHash,
            'phone' => $payload->phoneHash,
            'ip' => $payload->ip,
            'user_agent' => $payload->userAgent,
            'ttclid' => $payload->ids['ttclid'] ?? null,
            'ttp' => $payload->ids['ttp'] ?? null,
            'external_id' => $payload->extra['user']['external_id'] ?? null,
        ]);

        $body = array_filter([
            'event_source' => 'web',
            'event_source_id' => $settings->tiktok_pixel_id,
            'test_event_code' => $testCode,
            'data' => [array_filter([
                'event' => $event,
                'event_time' => $payload->eventTime,
                'event_id' => $payload->eventId,
                'user' => $user,
                'page' => $payload->sourceUrl ? ['url' => $payload->sourceUrl] : null,
                'properties' => $payload->customData() ?: null,
            ])],
        ]);

        Http::timeout(10)->acceptJson()->asJson()
            ->withHeaders(['Access-Token' => $token])
            ->post(config('services.tiktok.events_url'), $body)
            ->throw();

        return true;
    }
}
