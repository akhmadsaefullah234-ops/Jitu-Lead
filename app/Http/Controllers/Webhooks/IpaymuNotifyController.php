<?php

namespace App\Http\Controllers\Webhooks;

use App\Billing\Ipaymu\CallbackSignature;
use App\Billing\Ipaymu\IpaymuPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * iPaymu's payment notification. The signature is checked first, then the transaction is asked for again
 * from iPaymu itself; only a verified, matching, successful payment activates the plan.
 */
class IpaymuNotifyController
{
    public function __invoke(Request $request, IpaymuPayments $payments): JsonResponse
    {
        $payload = $this->payload($request);
        $context = ['trx_id' => $payload['trx_id'] ?? null, 'status' => $payload['status'] ?? null, 'reference_id' => $payload['reference_id'] ?? $payload['referenceId'] ?? null];

        if (! $payments->enabled() || $payload === []) {
            Log::warning('iPaymu callback ditolak: tidak aktif atau kosong', $context);

            return response()->json(['message' => 'ignored'], 400);
        }

        $signature = $request->header('X-Signature') ?? $request->header('signature');

        if (! CallbackSignature::valid($payload, $signature, (string) config('services.ipaymu.va'))) {
            Log::warning('iPaymu callback ditolak: tanda tangan salah', $context);

            return response()->json(['message' => 'invalid signature'], 400);
        }

        $result = $payments->handleCallback($payload);
        Log::info('iPaymu callback', $context + ['hasil' => $result]);

        return match ($result) {
            'unknown', 'mismatch' => response()->json(['message' => $result], 422),
            // iPaymu retries anything that is not 200, which is what we want while its own API is unreachable.
            'unverified' => response()->json(['message' => 'try again'], 503),
            default => response()->json(['message' => 'ok']),
        };
    }

    /** Raw body on purpose: Laravel's input trimming would change the very bytes the signature covers. */
    private function payload(Request $request): array
    {
        $raw = $request->getContent();

        if (str_contains((string) $request->header('Content-Type'), 'json')) {
            $data = json_decode($raw, true);

            return is_array($data) ? $data : [];
        }

        parse_str($raw, $data);

        return $data;
    }
}
