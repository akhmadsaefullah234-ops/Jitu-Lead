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
 * Credentials: base_url, api_key, signing_secret. When a channel has none of
 * them, the platform gateway from config/whatsapp.php is used, and every
 * request names the channel's session in X-Session-Id.
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
        $secret = $this->signingSecret();
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

    /** Starts a pairing session; returns a problem text, or null when the gateway is ready to show a QR. */
    public function startSession(): ?string
    {
        if ($problem = $this->configurationProblem()) {
            return $problem;
        }

        try {
            $response = $this->http()->post($this->url('/session/start'), [
                'webhook_url' => $this->channel->webhookUrl(),
                'signing_secret' => $this->signingSecret(),
            ]);
        } catch (Throwable $e) {
            return 'Tidak bisa menghubungi gateway: '.$e->getMessage();
        }

        return $response->successful() ? null : "Gateway menolak memulai sesi (HTTP {$response->status()}).";
    }

    /**
     * Latest pairing state: status is connected, qr_required or error.
     *
     * @return array{status: string, qr: ?string, error: ?string}
     */
    public function pairing(): array
    {
        if ($problem = $this->configurationProblem()) {
            return ['status' => 'error', 'qr' => null, 'error' => $problem];
        }

        try {
            $response = $this->http()->get($this->url('/session/qr'));
        } catch (Throwable $e) {
            return ['status' => 'error', 'qr' => null, 'error' => 'Tidak bisa menghubungi gateway.'];
        }

        if (! $response->successful()) {
            return ['status' => 'error', 'qr' => null, 'error' => "Gateway menjawab HTTP {$response->status()}."];
        }

        $status = (string) $response->json('status');

        return [
            'status' => $status === 'connected' ? 'connected' : 'qr_required',
            'qr' => $status === 'connected' ? null : $response->json('qr'),
            'error' => null,
        ];
    }

    public function logout(): ?string
    {
        if ($problem = $this->configurationProblem()) {
            return $problem;
        }

        try {
            $response = $this->http()->post($this->url('/session/logout'));
        } catch (Throwable $e) {
            return 'Tidak bisa menghubungi gateway.';
        }

        return $response->successful() ? null : "Gateway menjawab HTTP {$response->status()}.";
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
        if (blank($this->baseUrl()) || blank($this->apiKey())) {
            return $this->channel->usesPlatformGateway()
                ? 'Layanan WhatsApp QR belum aktif di server ini. Hubungi pengelola.'
                : 'URL dan API key gateway belum diisi.';
        }

        // Only addresses typed in by an agency are checked; the platform's own is set by the operator.
        return $this->channel->usesPlatformGateway() ? null : GatewayUrlGuard::problem($this->baseUrl());
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->apiKey())
            ->withHeaders(['X-Session-Id' => (string) $this->channel->webhook_token])
            ->acceptJson()
            ->timeout(config('whatsapp.timeout'))
            ->withoutRedirecting();
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->baseUrl(), '/').$path;
    }

    private function baseUrl(): ?string
    {
        return $this->channel->usesPlatformGateway() ? config('whatsapp.gateway.url') : $this->channel->credential('base_url');
    }

    private function apiKey(): ?string
    {
        return $this->channel->usesPlatformGateway() ? config('whatsapp.gateway.api_key') : $this->channel->credential('api_key');
    }

    private function signingSecret(): ?string
    {
        return $this->channel->usesPlatformGateway() ? config('whatsapp.gateway.signing_secret') : $this->channel->credential('signing_secret');
    }
}
