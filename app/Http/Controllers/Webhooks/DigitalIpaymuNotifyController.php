<?php

namespace App\Http\Controllers\Webhooks;

use App\Billing\Ipaymu\CallbackSignature;
use App\Models\Tenant;
use App\Payments\DigitalCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * iPaymu's payment notification for an agency's digital-product invoice. Apart from JITU's subscription callback
 * (IpaymuNotifyController): the agency's own VA signs it, and the transaction is asked for again from iPaymu with the
 * agency's own key before anything is marked paid.
 */
class DigitalIpaymuNotifyController
{
    public function __invoke(Request $request, int $tenant, DigitalCheckout $checkout): JsonResponse
    {
        $payload = $this->payload($request);
        $agency = Tenant::query()->find($tenant);
        $gateway = DigitalCheckout::gateway($agency);
        $context = ['tenant_id' => $tenant, 'trx_id' => $payload['trx_id'] ?? null, 'reference_id' => $payload['reference_id'] ?? $payload['referenceId'] ?? null];

        if ($gateway === null || $payload === []) {
            Log::warning('Callback iPaymu digital ditolak: tidak aktif atau kosong', $context);

            return response()->json(['message' => 'ignored'], 400);
        }

        if (! CallbackSignature::valid($payload, $request->header('X-Signature') ?? $request->header('signature'), (string) $gateway->va)) {
            Log::warning('Callback iPaymu digital ditolak: tanda tangan salah', $context);

            return response()->json(['message' => 'invalid signature'], 400);
        }

        return match ($result = $checkout->handleCallback($agency, $gateway, $payload)) {
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
