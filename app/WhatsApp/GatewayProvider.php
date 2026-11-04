<?php

namespace App\WhatsApp;

use App\Enums\MessageStatus;
use App\Models\WaChannel;
use App\Support\PhoneNumber;
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

    public function sendMedia(string $to, string $type, string $mime, string $filename, string $bytes, ?string $caption = null): SendResult
    {
        return $this->post('/messages', [
            'to' => $to, 'type' => $type, 'text' => $caption,
            'media' => ['mime_type' => $mime, 'filename' => $filename, 'data' => base64_encode($bytes)],
        ], timeout: 60);
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
                        media: $this->mediaOf($request, $type),
                        sentAt: InboundMessage::at($request->input('timestamp')),
                        contactName: $request->input('name'),
                        // A chat that starts from a Click-to-WhatsApp ad (contextInfo.externalAdReply).
                        referral: $request->boolean('from_me') ? null : InboundMessage::referral($request->input('referral')),
                        fromMe: $request->boolean('from_me'),
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
                $session = in_array($request->input('status'), ['connected', 'disconnected', 'qr_required', 'needs_relink'], true) ? $request->input('status') : null;
                // Why the login needs a new scan, e.g. "perangkat tertaut dilepas dari HP".
                $reason = is_string($request->input('reason')) && $request->input('reason') !== '' ? mb_substr(strip_tags($request->input('reason')), 0, 200) : null;
                break;
        }

        return ['messages' => $messages, 'statuses' => $statuses, 'session' => $session, 'session_reason' => $reason ?? null];
    }

    /**
     * The media details of a picture, voice note, sticker or video (a file too big for the webhook waits at the
     * gateway under the message id: `fetch`), or what a location, contact card or reaction says.
     *
     * @return array<string, mixed>|null
     */
    private function mediaOf(Request $request, string $type): ?array
    {
        $media = match ($type) {
            'location' => MessageContent::location($request->input('location')),
            'contacts' => ($contacts = MessageContent::contacts($request->input('contacts'))) ? ['contacts' => $contacts] : null,
            'reaction' => MessageContent::reaction($request->input('reaction')),
            default => MessageContent::media($request->input('media')),
        };

        if (($media['fetch'] ?? false) === true) {
            unset($media['fetch'], $media['data']);
            $media['id'] = (string) $request->input('id');
        }

        return $media === [] ? null : $media;
    }

    /** The bytes of a file the gateway kept because it was too big for the webhook, or null when it is gone. */
    public function downloadMedia(string $messageId): ?string
    {
        if ($this->configurationProblem() !== null) {
            return null;
        }

        try {
            $response = $this->http()->timeout(45)->accept('application/octet-stream')->get($this->url('/media/'.rawurlencode($messageId)));

            return $response->successful() && $response->body() !== '' ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
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

    /**
     * The WhatsApp Business labels of the number (trial: App\WhatsApp\BusinessLabels).
     *
     * @return array{labels: list<array{id: string, name: string, color: ?int}>, error: ?string}
     */
    public function labels(): array
    {
        if ($problem = $this->configurationProblem()) {
            return ['labels' => [], 'error' => $problem];
        }

        try {
            $response = $this->http()->get($this->url('/sessions/'.$this->channel->webhook_token.'/labels'));
        } catch (Throwable $e) {
            return ['labels' => [], 'error' => 'Tidak bisa menghubungi gateway.'];
        }

        if (! $response->successful()) {
            return ['labels' => [], 'error' => $response->status() === 404 ? 'Gateway belum mendukung label. Pasang ulang gateway.' : "Gateway menjawab HTTP {$response->status()}."];
        }

        return ['labels' => self::cleanLabels((array) $response->json('labels')), 'error' => null];
    }

    /** @return list<array{id: string, name: string, color: ?int}> */
    private static function cleanLabels(array $labels): array
    {
        $labels = array_values(array_filter($labels, fn ($l) => is_array($l) && filled($l['name'] ?? null)));

        return array_map(fn (array $l) => ['id' => (string) ($l['id'] ?? ''), 'name' => mb_substr((string) $l['name'], 0, 60), 'color' => isset($l['color']) ? (int) $l['color'] : null], $labels);
    }

    /**
     * Asks the gateway to fetch every WhatsApp Business label again (a number linked before the gateway kept labels
     * has none until then), and returns them. `retry_after`: seconds until another resync is allowed (the gateway
     * allows one per 10 minutes); the labels are then the ones already known. An older gateway without resync
     * answers with the labels it has and an error saying it needs reinstalling.
     *
     * @return array{labels: list<array{id: string, name: string, color: ?int}>, error: ?string, resynced: bool, retry_after: ?int}
     */
    public function resyncLabels(): array
    {
        if ($problem = $this->configurationProblem()) {
            return ['labels' => [], 'error' => $problem, 'resynced' => false, 'retry_after' => null];
        }

        try {
            $response = $this->http()->timeout(60)->post($this->url('/sessions/'.$this->channel->webhook_token.'/labels/resync'));
        } catch (Throwable $e) {
            return ['labels' => [], 'error' => 'Tidak bisa menghubungi gateway.', 'resynced' => false, 'retry_after' => null];
        }

        if ($response->status() === 404) {
            $known = $this->labels();

            return ['labels' => $known['labels'], 'error' => $known['error'] ?? 'Gateway belum bisa sinkron ulang label. Pasang ulang gateway.', 'resynced' => false, 'retry_after' => null];
        }

        if (! $response->successful()) {
            return ['labels' => [], 'resynced' => false, 'retry_after' => null, 'error' => match (true) {
                $response->json('error') === 'resync_failed' => 'WhatsApp tidak menjawab saat label diminta. Coba lagi beberapa menit lagi.',
                $response->status() === 503 => 'Nomor ini belum terhubung. Scan QR dulu.',
                default => "Gateway menjawab HTTP {$response->status()}.",
            }];
        }

        return [
            'labels' => self::cleanLabels((array) $response->json('labels')),
            'error' => null,
            'resynced' => (bool) $response->json('resynced'),
            'retry_after' => is_numeric($response->json('retry_after')) ? (int) $response->json('retry_after') : null,
        ];
    }

    /**
     * Puts a label the account already has on the chat with $phone. `code` says why not: label_not_found,
     * not_business, or error (worth trying again).
     *
     * @return array{ok: bool, code: ?string, already: bool, retry: bool}
     */
    public function addChatLabel(string $phone, string $label): array
    {
        if ($this->configurationProblem()) {
            return ['ok' => false, 'code' => 'error', 'already' => false, 'retry' => false];
        }

        try {
            $response = $this->http()->post($this->url('/sessions/'.$this->channel->webhook_token.'/chat-labels'), ['phone' => $phone, 'label' => $label]);
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'error', 'already' => false, 'retry' => true];
        }

        if ($response->successful()) {
            return ['ok' => true, 'code' => null, 'already' => (bool) $response->json('already'), 'retry' => false];
        }

        $code = $response->json('error');

        return in_array($code, ['label_not_found', 'not_business'], true)
            ? ['ok' => false, 'code' => $code, 'already' => false, 'retry' => false]
            : ['ok' => false, 'code' => 'error', 'already' => false, 'retry' => $response->status() >= 500 || $response->status() === 429];
    }

    private function post(string $path, array $payload, ?int $timeout = null): SendResult
    {
        if ($problem = $this->configurationProblem()) {
            return SendResult::failed($problem, true);
        }

        try {
            $response = $this->http()->timeout($timeout ?? config('whatsapp.timeout'))->post($this->url($path), $payload);
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
