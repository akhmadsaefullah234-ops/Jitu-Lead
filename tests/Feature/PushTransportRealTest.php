<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Push\PushTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Tests\TestCase;

/** Builds the real WebPush object (no mocked constructor) so a wrong library signature fails here, not in production. */
class PushTransportRealTest extends TestCase
{
    public function test_the_real_library_builds_and_sends_with_a_ten_second_client(): void
    {
        $vapid = VAPID::createVapidKeys();
        config(['jitu.push' => ['public_key' => $vapid['publicKey'], 'private_key' => $vapid['privateKey'], 'subject' => 'https://jitulead.test']]);

        $device = VAPID::createVapidKeys();
        $sub = new PushSubscription([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'p256dh' => $device['publicKey'],
            'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'content_encoding' => 'aes128gcm',
        ]);
        $sub->id = 7;

        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($history));

        $transport = new class($stack) extends PushTransport
        {
            public function __construct(private HandlerStack $stack) {}

            protected function httpClient(): ClientInterface
            {
                return new Client(['handler' => $this->stack, 'timeout' => 10, 'allow_redirects' => false, 'http_errors' => false]);
            }
        };

        $this->assertSame([7 => 'ok'], $transport->send(collect([$sub]), ['title' => 'Tes', 'body' => 'Halo', 'url' => 'https://jitulead.test/app']));
        $this->assertCount(1, $history);
        $this->assertSame('https://fcm.googleapis.com/fcm/send/abc', (string) $history[0]['request']->getUri());
    }

    public function test_the_default_client_has_a_timeout_and_does_not_follow_redirects(): void
    {
        $client = (new class extends PushTransport
        {
            public function make(): ClientInterface
            {
                return $this->httpClient();
            }
        })->make();

        $this->assertSame(10, $client->getConfig('timeout'));
        $this->assertFalse($client->getConfig('allow_redirects'));
    }
}
