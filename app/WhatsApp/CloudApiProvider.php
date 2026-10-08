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
 * The official WhatsApp Business Cloud API.
 *
 * Credentials: phone_number_id, access_token, app_secret, verify_token.
 */
class CloudApiProvider implements WhatsAppProvider
{
    public function __construct(private WaChannel $channel) {}

    public function sendText(string $to, string $text): SendResult
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->digits($to),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    public function sendTemplate(string $to, string $name, string $language, array $parameters = []): SendResult
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];

        if ($parameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], array_values($parameters)),
            ]];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $this->digits($to),
            'type' => 'template',
            'template' => $template,
        ]);
    }

    public function checkConnection(): ?string
    {
        if (blank($this->channel->credential('phone_number_id')) || blank($this->channel->credential('access_token'))) {
            return 'Phone Number ID dan Access Token belum diisi.';
        }

        try {
            $response = $this->http()->get($this->baseUrl().'/'.$this->channel->credential('phone_number_id'), ['fields' => 'display_phone_number,verified_name']);
        } catch (Throwable $e) {
            return 'Tidak bisa menghubungi Meta: '.$e->getMessage();
        }

        return $response->successful() ? null : $this->errorFrom($response->json(), $response->status());
    }

    public function verifySignature(Request $request): bool
    {
        $secret = $this->channel->credential('app_secret');
        $header = (string) $request->header('X-Hub-Signature-256');

        if (blank($secret) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($header, 7));
    }

    public function parseWebhook(Request $request): array
    {
        $messages = [];
        $statuses = [];
        $phoneNumberId = $this->channel->credential('phone_number_id');

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                // A webhook subscription can cover several numbers; only handle ours.
                if (($value['metadata']['phone_number_id'] ?? null) !== $phoneNumberId) {
                    continue;
                }

                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $contact) {
                    $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
                }

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $from = (string) ($message['from'] ?? '');
                    $type = (string) ($message['type'] ?? 'text');
                    $referral = $message['referral'] ?? null;

                    $messages[] = new InboundMessage(
                        externalId: (string) ($message['id'] ?? ''),
                        from: PhoneNumber::normalize($from) ?? $from,
                        type: $type,
                        body: $this->bodyOf($message, $type),
                        media: $this->mediaOf($message, $type),
                        sentAt: CarbonImmutable::createFromTimestamp((int) ($message['timestamp'] ?? time())),
                        contactName: $names[$from] ?? null,
                        referral: is_array($referral) ? array_filter([
                            'source_id' => $referral['source_id'] ?? null,
                            'source_type' => $referral['source_type'] ?? null,
                            'source_url' => $referral['source_url'] ?? null,
                            'headline' => $referral['headline'] ?? null,
                            'body' => $referral['body'] ?? null,
                            'ctwa_clid' => $referral['ctwa_clid'] ?? null,
                        ]) : null,
                    );
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $mapped = match ($status['status'] ?? null) {
                        'sent' => MessageStatus::Sent,
                        'delivered' => MessageStatus::Delivered,
                        'read' => MessageStatus::Read,
                        'failed' => MessageStatus::Failed,
                        default => null,
                    };

                    if ($mapped !== null && filled($status['id'] ?? null)) {
                        $statuses[] = new StatusUpdate($status['id'], $mapped, $status['errors'][0]['title'] ?? null);
                    }
                }
            }
        }

        return ['messages' => $messages, 'statuses' => $statuses, 'session' => null];
    }

    private function post(array $payload): SendResult
    {
        if (blank($this->channel->credential('phone_number_id')) || blank($this->channel->credential('access_token'))) {
            return SendResult::failed('Nomor resmi belum dikonfigurasi.', true);
        }

        try {
            $response = $this->http()->post($this->baseUrl().'/'.$this->channel->credential('phone_number_id').'/messages', $payload);
        } catch (Throwable $e) {
            return SendResult::failed('Tidak bisa menghubungi Meta: '.$e->getMessage(), true);
        }

        if (! $response->successful()) {
            return SendResult::failed($this->errorFrom($response->json(), $response->status()), SendResult::statusIsChannelProblem($response->status()));
        }

        return SendResult::sent($response->json('messages.0.id'));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->channel->credential('access_token'))
            ->acceptJson()
            ->timeout(config('whatsapp.timeout'));
    }

    private function baseUrl(): string
    {
        return rtrim(config('whatsapp.graph_url'), '/').'/'.config('whatsapp.graph_version');
    }

    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }

    private function errorFrom(mixed $json, int $status): string
    {
        $message = is_array($json) ? ($json['error']['message'] ?? null) : null;

        return $message ? "Meta menolak pesan ($status): $message" : "Meta menolak pesan (HTTP $status).";
    }

    private function bodyOf(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null,
            'image', 'document', 'video', 'audio' => $message[$type]['caption'] ?? null,
            'location' => trim(($message['location']['name'] ?? '').' '.($message['location']['address'] ?? '')) ?: null,
            default => null,
        };
    }

    private function mediaOf(array $message, string $type): ?array
    {
        return match ($type) {
            'image', 'document', 'video', 'audio' => array_filter([
                'id' => $message[$type]['id'] ?? null,
                'mime_type' => $message[$type]['mime_type'] ?? null,
                'filename' => $message[$type]['filename'] ?? null,
            ]),
            'location' => array_filter([
                'latitude' => $message['location']['latitude'] ?? null,
                'longitude' => $message['location']['longitude'] ?? null,
            ]),
            default => null,
        };
    }
}
