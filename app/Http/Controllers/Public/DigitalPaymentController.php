<?php

namespace App\Http\Controllers\Public;

use App\Models\DigitalPayment;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Tracking\PipelineConversions;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's side of a digital invoice: the page iPaymu returns to (status, and on a paid invoice the Purchase pixel
 * with the same event_id the server sends), and the signed access link that opens the product for this buyer only.
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

    /** Signed and time-limited (DigitalCheckout::accessUrl); opens only for a paid invoice. */
    public function access(string $token, CurrentTenant $current): Response
    {
        $payment = $this->find($token);

        return $current->run($payment->tenant, function () use ($payment) {
            $lead = $payment->lead;
            $target = (string) ($lead?->access_link ?: $lead?->property?->access_url);

            if ($payment->status !== DigitalPayment::PAID || $lead?->paid_at === null || ! preg_match('#^https?://#i', $target)) {
                return response()->view('public.digital-access-unavailable', ['tenant' => $payment->tenant], 410);
            }

            if ($payment->access_opened_at === null) {
                $payment->forceFill(['access_opened_at' => now()])->save();
                $lead->activities()->create(['type' => 'access_opened', 'body' => 'Pembeli membuka tautan akses produk.']);
            }

            return redirect()->away($target);
        });
    }

    private function find(string $token): DigitalPayment
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return DigitalPayment::query()->withoutGlobalScopes()->with(['tenant', 'lead' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()])])
            ->where('token', $token)->firstOrFail();
    }
}
