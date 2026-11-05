<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CarrierAccount;
use App\Models\Tenant;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A courier aggregator calls this when a tracking number is issued or a parcel's status changes. The URL holds
 * the agency's own token, and the provider's key header must match that agency's callback key, so one agency's
 * URL can never touch another agency's parcels. Everpro retries until it gets a 200, so anything we cannot use
 * still gets a 200; only a wrong key is refused.
 */
class ShippingCallbackController extends Controller
{
    public function __invoke(Request $request, string $provider, string $token, CurrentTenant $current): JsonResponse
    {
        $account = CarrierAccount::query()->withoutGlobalScopes()->where('callback_token', $token)->where('provider', $provider)->first();
        $tenant = $account ? Tenant::query()->find($account->tenant_id) : null;

        if ($account === null || $tenant === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        $carrier = Carriers::make($account);

        if (! $carrier->verifyCallback($request)) {
            Log::warning('Shipping callback with a wrong key', ['tenant' => $tenant->getKey(), 'provider' => $provider]);

            return response()->json(['status' => 'unauthorized'], 401);
        }

        try {
            $event = $carrier->parseCallback($request);

            if ($event !== null) {
                $current->run($tenant, fn () => app(Shipments::class)->callback($event));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['status' => 'ok']);
    }
}
