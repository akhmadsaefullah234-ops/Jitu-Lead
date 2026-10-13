<?php

namespace App\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers one notification to many devices through the browsers' push services.
 * Tests replace this class, so nothing leaves the machine.
 */
class PushTransport
{
    /** Push services only. A subscription's address comes from the browser, so anything else is refused. */
    private const HOSTS = ['googleapis.com', 'mozilla.com', 'mozaws.net', 'apple.com', 'windows.com', 'microsoft.com'];

    public function enabled(): bool
    {
        return filled(config('jitu.push.public_key')) && filled(config('jitu.push.private_key'));
    }

    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['port']) && $parts['port'] !== 443 || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::HOSTS as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array{title: string, body: string, url: string, tag?: string}  $payload
     * @return array<int, 'ok'|'gone'|'failed'> result per subscription id
     */
    public function send(Collection $subscriptions, array $payload): array
    {
        if (! $this->enabled() || $subscriptions->isEmpty()) {
            return [];
        }

        $push = new WebPush(['VAPID' => [
            'subject' => config('jitu.push.subject'),
            'publicKey' => config('jitu.push.public_key'),
            'privateKey' => config('jitu.push.private_key'),
        ]], ['TTL' => 3600, 'urgency' => 'high'], 10);

        $byEndpoint = [];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $sub) {
            if (! self::allowedEndpoint($sub->endpoint)) {
                $byEndpoint[$sub->endpoint] = [$sub->getKey(), 'gone'];

                continue;
            }

            $byEndpoint[$sub->endpoint] = [$sub->getKey(), 'failed'];
            $push->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->p256dh,
                'authToken' => $sub->auth,
                'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
            ]), $json);
        }

        foreach ($push->flush() as $report) {
            $endpoint = $report->getEndpoint();

            if (isset($byEndpoint[$endpoint])) {
                $byEndpoint[$endpoint][1] = $report->isSuccess() ? 'ok' : ($report->isSubscriptionExpired() ? 'gone' : 'failed');
            }
        }

        return collect($byEndpoint)->mapWithKeys(fn ($v) => [$v[0] => $v[1]])->all();
    }
}
