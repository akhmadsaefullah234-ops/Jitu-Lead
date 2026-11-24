<?php

namespace App\Http\Controllers\Public;

use App\Models\DigitalPayment;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Tracking\PipelineConversions;
use Illuminate\View\View;

/**
 * The buyer's side of a digital invoice: the page iPaymu returns to (status, and on a paid invoice the Purchase pixel
 * with the same event_id the server sends). The access link is App\Http\Controllers\Public\DigitalAccessController.
 */
class DigitalPaymentController
{
    public function show(string $token, CurrentTenant $current): View
    {
        $payment = $this->find($token);

        return $current->run($payment->tenant, fn () => view('public.digital-payment', [
            'payment' => $payment,
            'lead' => $payment->lead,
            'tenant' => $payment->tenant,
            'tracking' => TrackingSetting::active(),
            'purchaseEventId' => $payment->status === DigitalPayment::PAID && $payment->lead ? PipelineConversions::eventId($payment->lead, 'Purchase') : null,
        ]));
    }

    private function find(string $token): DigitalPayment
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return DigitalPayment::query()->withoutGlobalScopes()->with(['tenant', 'lead' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()])])
            ->where('token', $token)->firstOrFail();
    }
}
