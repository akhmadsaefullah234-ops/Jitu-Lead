<?php

namespace App\Billing\Ipaymu;

use App\Billing\Subscriptions;
use App\Filament\Pages\Subscription;
use App\Models\IpaymuPayment;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Support\OwnerAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Starts iPaymu payments for plan requests and turns a confirmed payment into an active plan, once. */
class IpaymuPayments
{
    public function __construct(private IpaymuClient $client) {}

    public function enabled(): bool
    {
        return $this->client->enabled();
    }

    /** The open payment page for the request when there is one, otherwise a fresh session. */
    public function start(SubscriptionRequest $request, User $buyer): IpaymuPayment
    {
        $open = $request->payments()->latest('id')->get()->first(fn (IpaymuPayment $p) => $p->isOpen());

        if ($open) {
            return $open;
        }

        $attempt = $request->payments()->count();
        $reference = $attempt === 0 ? (string) $request->getKey() : $request->getKey().'-'.($attempt + 1);
        $plan = config("plans.plans.{$request->plan}.name", $request->plan);
        $cycle = $request->billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan';
        $hours = max(1, (int) config('services.ipaymu.expired_hours'));
        $name = $request->tenant->name;

        $body = array_filter([
            'product' => ["JITU LEAD {$plan} ({$cycle})"],
            'qty' => ['1'],
            'price' => [(string) (int) $request->amount],
            'description' => ["Langganan JITU LEAD paket {$plan}, {$cycle}, untuk {$name}"],
            'returnUrl' => $this->pageUrl(),
            'cancelUrl' => $this->pageUrl(),
            'notifyUrl' => url('/billing/ipaymu/notify'),
            'referenceId' => $reference,
            'buyerName' => $buyer->name,
            'buyerEmail' => $buyer->email,
            'buyerPhone' => $buyer->phone ?? null,
            'expired' => $hours,
        ], fn ($v) => filled($v));

        $session = $this->client->createPayment($body);

        return IpaymuPayment::create([
            'subscription_request_id' => $request->getKey(), 'tenant_id' => $request->tenant_id, 'reference_id' => $reference,
            'session_id' => $session['session_id'], 'payment_url' => $session['url'], 'amount' => (int) $request->amount,
            'status' => IpaymuPayment::PENDING, 'expires_at' => now()->addHours($hours),
        ]);
    }

    private function pageUrl(): string
    {
        return Subscription::getUrl();
    }

    /**
     * Handles a callback whose signature already passed: asks iPaymu for the real status and applies it.
     *
     * @return string 'unknown' | 'mismatch' | 'unverified' | IpaymuPayment status
     */
    public function handleCallback(array $payload): string
    {
        $reference = (string) ($payload['reference_id'] ?? $payload['referenceId'] ?? '');
        $trxId = (string) ($payload['trx_id'] ?? '');
        $payment = $reference === '' ? null : IpaymuPayment::query()->where('reference_id', $reference)->first();

        if (! $payment || $trxId === '') {
            Log::warning('iPaymu callback ditolak: referensi tidak dikenal', ['trx_id' => $trxId, 'reference_id' => $reference]);

            return 'unknown';
        }

        try {
            $data = $this->client->checkTransaction($trxId);
        } catch (\Throwable $e) {
            Log::warning('iPaymu callback: cek transaksi gagal', ['trx_id' => $trxId, 'reference_id' => $reference, 'error' => $e->getMessage()]);

            return 'unverified';
        }

        $sameReference = (string) ($data['ReferenceId'] ?? '') === $payment->reference_id;
        $sameAmount = (int) round((float) ($data['Amount'] ?? -1)) === (int) $payment->amount;
        $sameSession = blank($data['SessionId'] ?? null) || blank($payment->session_id) || strcasecmp((string) $data['SessionId'], $payment->session_id) === 0;

        if (! $sameReference || ! $sameAmount || ! $sameSession) {
            Log::warning('iPaymu callback ditolak: data tidak cocok', [
                'trx_id' => $trxId, 'reference_id' => $reference, 'api_reference' => $data['ReferenceId'] ?? null,
                'amount_expected' => $payment->amount, 'amount_api' => $data['Amount'] ?? null,
            ]);

            return 'mismatch';
        }

        $status = match ((int) ($data['Status'] ?? 0)) {
            1, 6 => IpaymuPayment::PAID,
            -2 => IpaymuPayment::EXPIRED,
            2, 3, 4, 5 => IpaymuPayment::FAILED,
            default => IpaymuPayment::PENDING,
        };

        $result = $this->apply($payment->getKey(), $status, $trxId, (string) ($payload['via'] ?? $payload['channel'] ?? ''));

        Log::info('iPaymu callback diproses', ['trx_id' => $trxId, 'reference_id' => $reference, 'status_api' => $data['Status'] ?? null, 'hasil' => $result]);

        return $result;
    }

    /** Applies a verified status. A payment that is already paid never changes and never activates the plan twice. */
    private function apply(int $paymentId, string $status, string $trxId, string $channel): string
    {
        $paidNow = null;

        $result = DB::transaction(function () use ($paymentId, $status, $trxId, $channel, &$paidNow) {
            $payment = IpaymuPayment::query()->lockForUpdate()->findOrFail($paymentId);
            $payment->forceFill(['trx_id' => $trxId, 'last_callback_at' => now()] + ($channel !== '' ? ['channel' => $channel] : []));

            if ($payment->status === IpaymuPayment::PAID) {
                $payment->save();

                return IpaymuPayment::PAID;
            }

            $payment->status = $status;

            if ($status === IpaymuPayment::PAID) {
                $payment->paid_at = now();
                $request = SubscriptionRequest::query()->lockForUpdate()->findOrFail($payment->subscription_request_id);

                // The request may already be approved by hand (a transfer arrived too); then only the owner is told.
                if ($request->status !== SubscriptionRequest::APPROVED) {
                    $days = $request->billing_cycle === 'yearly' ? 12 : 1;
                    app(Subscriptions::class)->activate($request->tenant, $request->plan, $request->billing_cycle, $days);
                    $request->update(['status' => SubscriptionRequest::APPROVED, 'handled_at' => now()]);
                    $paidNow = 'activated';
                } else {
                    $paidNow = 'already-active';
                }
            }

            $payment->save();

            return $status;
        });

        if ($paidNow !== null) {
            OwnerAlerts::paymentReceived(IpaymuPayment::query()->with('request.tenant')->findOrFail($paymentId), $paidNow === 'already-active');
        }

        return $result;
    }
}
