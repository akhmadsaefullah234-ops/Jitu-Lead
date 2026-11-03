<?php

namespace App\Tracking;

use App\Models\TrackingSetting;
use App\Models\WaChannel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Conversions API for Business Messaging (beta): the event of a chat that began with a Click-to-WhatsApp ad on
 * the official WhatsApp Cloud API, tied to the ad click by its ctwa_clid. It goes to the dataset of the number's
 * WhatsApp Business Account, which the Dataset API gives (and creates when there is none); its id is kept on the
 * channel. Uses the channel's own access token.
 */
class BusinessMessagingConversions
{
    /** @return bool whether an event was sent (false: not set up for this lead) */
    public function send(TrackingSetting $settings, ConversionPayload $payload): bool
    {
        $messaging = $payload->extra['messaging'] ?? null;

        if (! is_array($messaging) || $payload->isTest() || ! $settings->pipeline()['messaging']) {
            return false;
        }

        $channel = WaChannel::query()->find($messaging['channel_id'] ?? null);
        $token = $channel?->credential('access_token');

        if ($channel === null || ! $channel->isOfficial() || blank($token) || blank($messaging['waba_id'] ?? null) || blank($messaging['ctwa_clid'] ?? null)) {
            return false;
        }

        $custom = array_intersect_key($payload->customData(), array_flip(['currency', 'value']));

        $this->http($token)->post($this->url($this->dataset($channel, $token).'/events'), [
            'data' => [array_filter([
                'event_name' => $messaging['event'],
                'event_time' => $payload->eventTime,
                'event_id' => $payload->eventId,
                'action_source' => 'business_messaging',
                'messaging_channel' => 'whatsapp',
                'user_data' => [
                    'whatsapp_business_account_id' => (string) $messaging['waba_id'],
                    'ctwa_clid' => (string) $messaging['ctwa_clid'],
                ],
                'custom_data' => isset($custom['value']) ? $custom : null,
            ])],
        ])->throw();

        return true;
    }

    /** The dataset id of the channel's WhatsApp Business Account: kept, else asked from the Dataset API. */
    private function dataset(WaChannel $channel, string $token): string
    {
        if (filled($channel->credential('dataset_id'))) {
            return (string) $channel->credential('dataset_id');
        }

        $waba = (string) $channel->credential('waba_id');
        $id = $this->http($token)->get($this->url($waba.'/dataset'))->throw()->json('data.0.id')
            ?? $this->http($token)->post($this->url($waba.'/dataset'))->throw()->json('id');

        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('Dataset API returned no dataset id.');
        }

        $channel->forceFill(['credentials' => ['dataset_id' => (string) $id] + (array) $channel->credentials])->save();

        return (string) $id;
    }

    private function url(string $path): string
    {
        return rtrim(config('services.meta.graph_url'), '/').'/'.config('services.meta.graph_version').'/'.$path;
    }

    private function http(string $token): PendingRequest
    {
        return Http::timeout(10)->acceptJson()->asJson()->withToken($token);
    }
}
