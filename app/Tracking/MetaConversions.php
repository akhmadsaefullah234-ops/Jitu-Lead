<?php

namespace App\Tracking;

use App\Models\TrackingSetting;
use Illuminate\Support\Facades\Http;

/**
 * Meta Conversions API. For a form: the same event the browser pixel fired ("Lead" by default), with the same
 * event_id so Meta counts it once (action_source "website"). For a lead's progress in the CRM: the stage's event,
 * action_source "system_generated", with every contact detail hashed (App\Tracking\PipelineConversions).
 */
class MetaConversions
{
    /** @return bool whether an event was sent (false: not set up, or nothing to send) */
    public function send(TrackingSetting $settings, ConversionPayload $payload): bool
    {
        $token = $settings->credential('meta_capi_token');
        $event = $payload->eventName('meta', $settings->eventFor('meta'));
        $testCode = $settings->credential('meta_test_event_code');

        if (blank($settings->meta_pixel_id) || blank($token) || $event === null || ($payload->isTest() && blank($testCode))) {
            return false;
        }

        $user = array_filter([
            'em' => $payload->emailHash ? [$payload->emailHash] : null,
            'ph' => $payload->phoneHash ? [$payload->phoneHash] : null,
            'client_ip_address' => $payload->ip,
            'client_user_agent' => $payload->userAgent,
            'fbp' => $payload->ids['fbp'] ?? null,
            'fbc' => $payload->ids['fbc'] ?? null,
        ]) + (array) ($payload->extra['user'] ?? []);

        $body = array_filter([
            'data' => [array_filter([
                'event_name' => $event,
                'event_time' => $payload->eventTime,
                'event_id' => $payload->eventId,
                'action_source' => $payload->actionSource(),
                'event_source_url' => $payload->actionSource() === 'website' ? $payload->sourceUrl : null,
                'user_data' => $user,
                'custom_data' => $payload->customData() ?: null,
            ])],
            'test_event_code' => $testCode,
        ]);

        Http::timeout(10)->acceptJson()->asJson()
            ->withToken($token)
            ->post(rtrim(config('services.meta.graph_url'), '/').'/'.config('services.meta.graph_version').'/'.$settings->meta_pixel_id.'/events', $body)
            ->throw();

        return true;
    }
}
