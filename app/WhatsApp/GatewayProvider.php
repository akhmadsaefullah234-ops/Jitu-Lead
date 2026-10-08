<?php

namespace App\WhatsApp;

use App\Enums\MessageStatus;
use App\Models\WaChannel;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Adapter for the in-house WhatsApp gateway. The contract it expects is in
 * docs/whatsapp-gateway-contract.md.
 *
 * Credentials: base_url, api_key, signing_secret.
 */
class GatewayProvider implements WhatsAppProvider
{
    public function __construct(private WaChannel $channel) {}

    public function sendText(string $to, string $text): SendResult
    {
        return $this->post('/messages', ['to' => $to, 'type' => 'text', 'text' => $text]);
    }

    public function sendTemplate(string $to, string $name, string $language, array $parameters = []): SendResult
    {
        return SendResult::failed('Gateway tidak mendukung template. Gunakan nomor resmi.');
    }

    public function checkConnection(): ?string
    {
        if ($problem = $this->configurationProblem()) {
            return $problem;
        }

        try {
            $response = $this->http()->get($this->url('/session'));
        } catch (Throwable $e) {
            return 'Tidak bisa menghubungi gateway: '.$e->getMessage();
        }

        if (! $response->successful()) {
            return "Gateway menjawab HTTP {$response->status()}.";
        }

        return match ($response->json('status')) {
            'connected' => null,
            'qr_required' => 'Sesi gateway terputus dan perlu scan QR ulang.',
            default => 'Sesi gateway belum terhubung.',
        };
    }

    public function verifySignature(Request $request): bool
    {
        $secret = $this->channel->credential('signing_secret');
        $header = (string) $request->header('X-Gateway-Signature');

        if (blank($secret) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($header, 7));
    }

    public function parseWebhook(Request $request): array
    {
        $messages = [];
        $statuses = [];
        $session = null;

        switch ($request->input('event')) {
            case 'message':
                $from = (string) $request->input('from', '');
                $type = (string) $request->input('type', 'text');

                if (filled($request->input('id')) && $from !== '') {
                    $messages[] = new InboundMessage(
                        externalId: (string) $request->input('id'),
                        from: PhoneNumber::normalize($from) ?? $from,
                        type: $type,
                        body: $request->input('text'),
                        media: is_array($request->input('media')) ? $request->input('media') : null,
                        sentAt: CarbonImmutable::createFromTimestamp((int) $request->input('timestamp', time())),
                        contactName: $request->input('name'),
                    );
                }
                break;

            case 'status':
                $mapped = match ($request->input('status')) {
                    'sent' => MessageStatus::Sent,
                    'delivered' => MessageStatus::Delivered,
                    'read' => MessageStatus::Read,
                    'failed' => MessageStatus::Failed,
                    default => null,
                };

                if ($mapped !== null && filled($request->input('id'))) {
                    $statuses[] = new StatusUpdate((string) $request->input('id'), $mapped, $request->input('error'));
                }
                break;

            case 'session':
                $session = in_array($request->input('status'), ['connected', 'disconnected', 'qr_required'], true) ? $request->input('status') : null;
                break;
        }

        return ['messages' => $messages, 'statuses' => $statuses, 'session' => $session];
    }

    private function post(string $path, array $payload): SendResult
    {
        if ($problem = $this->configurationProblem()) {
            return SendResult::failed($problem, true);
        }

        try {
            $response = $this->http()->post($this->url($path), $payload);
        } catch (Throwable $e) {
            return SendResult::failed('Tidak bisa menghubungi gateway: '.$e->getMessage(), true);
        }

        if (! $response->successful()) {
            return SendResult::failed($response->json('error') ?: "Gateway menolak pesan (HTTP {$response->status()}).", SendResult::statusIsChannelProblem($response->status()));
        }

        return SendResult::sent($response->json('id'));
    }

    private function configurationProblem(): ?string
    {
        if (blank($this->channel->credential('base_url')) || blank($this->channel->credential('api_key'))) {
            return 'URL dan API key gateway belum diisi.';
        }

        return GatewayUrlGuard::problem($this->channel->credential('base_url'));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->channel->credential('api_key'))
            ->acceptJson()
            ->timeout(config('whatsapp.timeout'))
            ->withoutRedirecting();
    }

    private function url(string $path): string
    {
        return rtrim($this->channel->credential('base_url'), '/').$path;
    }
}
