<?php

namespace App\Billing\Ipaymu;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the iPaymu API v2. Requests are signed as documented at docs.ipaymu.com/en/docs/signature:
 * HMAC-SHA256 over "POST:VA:sha256(body):API_KEY", keyed with the API key. The key itself is never logged.
 */
class IpaymuClient
{
    /** An agency's own account (digital products); null: JITU's account for subscriptions (config/services.php). */
    private ?array $account = null;

    /** A copy of this client that signs with an agency's own iPaymu account instead of JITU's. */
    public function using(string $va, string $apiKey, bool $sandbox): static
    {
        $client = clone $this;
        $client->account = ['va' => $va, 'api_key' => $apiKey, 'sandbox' => $sandbox];

        return $client;
    }

    public function enabled(): bool
    {
        return filled($this->va()) && filled($this->apiKey());
    }

    public function baseUrl(): string
    {
        return ($this->account !== null ? $this->account['sandbox'] : config('services.ipaymu.sandbox')) ? 'https://sandbox.ipaymu.com' : 'https://my.ipaymu.com';
    }

    private function va(): string
    {
        return (string) ($this->account['va'] ?? config('services.ipaymu.va'));
    }

    private function apiKey(): string
    {
        return (string) ($this->account['api_key'] ?? config('services.ipaymu.api_key'));
    }

    public function signature(string $jsonBody, string $va, string $apiKey): string
    {
        $stringToSign = 'POST:'.$va.':'.strtolower(hash('sha256', $jsonBody)).':'.$apiKey;

        return hash_hmac('sha256', $stringToSign, $apiKey);
    }

    /**
     * Creates a redirect payment session.
     *
     * @return array{session_id: string, url: string}
     *
     * @throws \RuntimeException when iPaymu refuses or cannot be reached
     */
    public function createPayment(array $body): array
    {
        $data = $this->post('/api/v2/payment', $body)['Data'] ?? [];

        if (blank($data['SessionID'] ?? null) || blank($data['Url'] ?? null)) {
            throw new \RuntimeException('iPaymu tidak mengembalikan alamat pembayaran.');
        }

        return ['session_id' => (string) $data['SessionID'], 'url' => (string) $data['Url']];
    }

    /**
     * Asks iPaymu for the real state of a transaction; the callback alone is never trusted.
     *
     * @return array<string, mixed> the "Data" part of the answer
     *
     * @throws \RuntimeException
     */
    public function checkTransaction(string $transactionId): array
    {
        return $this->post('/api/v2/transaction', ['transactionId' => $transactionId])['Data'] ?? [];
    }

    private function post(string $path, array $body): array
    {
        $va = $this->va();
        $key = $this->apiKey();

        if ($va === '' || $key === '') {
            throw new \RuntimeException('iPaymu belum dikonfigurasi.');
        }

        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = Http::timeout(20)->withHeaders([
            'va' => $va,
            'signature' => $this->signature($json, $va, $key),
            'timestamp' => now()->format('YmdHis'),
        ])->withBody($json, 'application/json')->post($this->baseUrl().$path);

        $answer = $response->json();

        if (! $response->successful() || ! is_array($answer) || (int) ($answer['Status'] ?? 0) !== 200) {
            throw new \RuntimeException('iPaymu menolak permintaan ('.$path.', HTTP '.$response->status().'): '.($answer['Message'] ?? 'tanpa pesan'));
        }

        return $answer;
    }
}
