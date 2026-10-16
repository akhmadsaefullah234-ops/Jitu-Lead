<?php

namespace App\Billing;

use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OwnerAlerts;

/** An agency admin asking for a plan; the platform owner activates it once the transfer is confirmed. */
class PlanRequests
{
    /** Creates the request and replaces an older pending one. Null when the plan or cycle is unknown. */
    public static function create(Tenant $tenant, User $user, string $plan, string $cycle): ?SubscriptionRequest
    {
        $price = config("plans.plans.$plan.price.$cycle");

        if ($price === null || ! in_array($cycle, ['monthly', 'yearly'], true)) {
            return null;
        }

        SubscriptionRequest::query()->where('tenant_id', $tenant->getKey())->where('status', SubscriptionRequest::PENDING)
            ->update(['status' => SubscriptionRequest::CANCELLED, 'handled_at' => now()]);

        $request = SubscriptionRequest::create([
            'tenant_id' => $tenant->getKey(), 'requested_by' => $user->getKey(), 'plan' => $plan,
            'billing_cycle' => $cycle, 'amount' => $price,
        ]);

        OwnerAlerts::planRequested($request);

        return $request;
    }
}
