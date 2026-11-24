<?php

namespace App\Payments;

use App\Actions\RecordPurchase;
use App\Automation\DigitalStageAutomation;
use App\Billing\Ipaymu\IpaymuClient;
use App\Enums\Role;
use App\Models\DigitalPayment;
use App\Models\Lead;
use App\Models\PaymentGateway;
use App\Models\Tenant;
use App\Models\User;
use App\Shipping\ShippingQuote;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tahap 3, automatic payment for digital products (industry "digital") through the agency's own iPaymu account:
 * an invoice (QRIS or virtual account, chosen on iPaymu's page) for the total priced from the catalogue, its link sent
 * to the buyer on WhatsApp, and the callback that marks the purchase paid. A purchase is paid only when iPaymu itself
 * says so (the callback is signed and then checked again with iPaymu), never on the buyer's or the AI's word.
 * Separate from JITU's own subscription payments (App\Billing\Ipaymu\IpaymuPayments): own route, own table, own keys.
 */
class DigitalCheckout
{
    public const ORIGIN = 'payment_link';

    /** Invoices one lead may start in 24 hours; more is refused (a loop, or someone trying). */
    public const MAX_INVOICES_PER_DAY = 5;

    /** How long before an invoice expires the buyer is reminded, once. */
    public const REMIND_HOURS_BEFORE = 3;

    public const MESSAGE = "Halo {nama}, pembelian {produk} sudah kami catat. Total {total}.\nBayar lewat QRIS atau virtual account di tautan ini:\n{tautan}\nBerlaku sampai {batas}. Akses dikirim otomatis begitu pembayaran diterima.";

    public const REMINDER = "Halo {nama}, tautan bayar {produk} sebesar {total} berlaku sampai {batas}:\n{tautan}\nAbaikan pesan ini bila sudah membayar.";

    public function __construct(private IpaymuClient $client, private PaymentInstructions $whatsapp) {}

    /** The agency's iPaymu account when it is a digital agency and the account is switched on and complete. */
    public static function gateway(?Tenant $tenant): ?PaymentGateway
    {
        if ($tenant?->industry()->key !== 'digital') {
            return null;
        }

        $gateway = PaymentGateway::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->first();

        return $gateway?->ready() ? $gateway : null;
    }

    public static function applies(?Tenant $tenant): bool
    {
        return self::gateway($tenant) !== null;
    }

    /**
     * Opens (or reuses) the invoice for this lead's total and sends its link on WhatsApp.
     *
     * @return array{status: string, pesan: string, url?: string, nominal?: int}
     */
    public function start(Lead $lead, int $total, User $actor, bool $send = true): array
    {
        $gateway = self::gateway($lead->tenant);

        if ($gateway === null || $total < 1) {
            return ['status' => 'tidak_berlaku', 'pesan' => 'Pembayaran otomatis belum aktif atau total belum diketahui.'];
        }

        $open = DigitalPayment::query()->where('lead_id', $lead->getKey())->where('amount', $total)->latest('id')->get()->first(fn (DigitalPayment $p) => $p->isOpen());

        if ($open === null) {
            if (DigitalPayment::query()->where('lead_id', $lead->getKey())->where('created_at', '>=', now()->subDay())->count() >= self::MAX_INVOICES_PER_DAY) {
                Log::warning('Invoice digital ditolak: batas percobaan', ['tenant_id' => $lead->tenant_id, 'lead_id' => $lead->getKey()]);

                return ['status' => 'batas_percobaan', 'pesan' => 'Terlalu banyak tautan bayar untuk lead ini dalam 24 jam. Admin melanjutkan.'];
            }

            try {
                $open = $this->create($lead, $total, $gateway);
            } catch (\Throwable $e) {
                Log::warning('Invoice digital gagal dibuat', ['tenant_id' => $lead->tenant_id, 'lead_id' => $lead->getKey(), 'error' => $e->getMessage()]);
                $lead->activities()->create(['type' => 'payment_link', 'body' => 'Tautan bayar iPaymu gagal dibuat: '.$e->getMessage().'. Kirim instruksi bayar manual dari Inbox.']);

                return ['status' => 'gagal', 'pesan' => 'Tautan bayar belum bisa dibuat. Admin melanjutkan.'];
            }
        }

        $lead->forceFill(['payment_amount' => $total, 'payment_due_at' => $open->expires_at, 'payment_unique_code' => null])->save();

        if (! $send) {
            return ['status' => 'dibuat', 'pesan' => 'Tautan bayar dibuat.', 'url' => $open->payment_url, 'nominal' => $total];
        }

        $why = $this->whatsapp->deliver($lead, $actor, $this->text(self::MESSAGE, $lead, $open), self::ORIGIN);
        $summary = ShippingQuote::rupiah($total).', berlaku sampai '.PaymentInstructions::when($open->expires_at, $lead->tenant);

        if ($why !== null) {
            $lead->activities()->create(['type' => 'payment_link', 'body' => "Tautan bayar iPaymu dibuat ({$summary}) tapi tidak terkirim otomatis ({$why}). Salin dari halaman lead.",
                'meta' => ['payment_id' => $open->getKey(), 'nominal' => $total, 'terkirim' => false]]);

            return ['status' => 'tidak_terkirim', 'pesan' => $why, 'url' => $open->payment_url, 'nominal' => $total];
        }

        $lead->forceFill(['payment_instruction_sent_at' => now()])->save();
        $lead->activities()->create(['type' => 'payment_link', 'body' => "Tautan bayar iPaymu dikirim sistem: {$summary}.", 'meta' => ['payment_id' => $open->getKey(), 'nominal' => $total, 'terkirim' => true]]);

        return ['status' => 'terkirim', 'pesan' => 'Tautan bayar dikirim sistem.', 'url' => $open->payment_url, 'nominal' => $total];
    }

    /**
     * A digital form set to "Arahkan ke halaman bayar": the product the form matched is recorded as a confirmed purchase
     * (which sends the payment link on WhatsApp too) and the buyer's browser goes to iPaymu. Null: nothing to pay yet
     * (no gateway, no priced product, already paid), so the form falls back to its thank-you message.
     */
    public function fromForm(Lead $lead): ?string
    {
        if (! self::applies($lead->tenant) || $lead->paid_at !== null || $lead->property?->price_from === null) {
            return null;
        }

        $actor = $lead->owner ?? $lead->tenant->activeUsers()->wherePivot('role', Role::Admin->value)->first();

        if ($actor === null) {
            return null;
        }

        try {
            app(RecordPurchase::class)($lead, ['items' => [['product_id' => $lead->property_id, 'jumlah' => max(1, (int) $lead->quantity)]], 'status' => 'konfirmasi'], $actor, 'formulir');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return DigitalPayment::query()->where('lead_id', $lead->getKey())->latest('id')->get()->first(fn (DigitalPayment $p) => $p->isOpen())?->payment_url;
    }

    private function create(Lead $lead, int $total, PaymentGateway $gateway): DigitalPayment
    {
        $hours = max(1, min(168, (int) $gateway->invoice_hours));
        $token = Str::random(40);
        $reference = 'D'.$lead->tenant_id.'-'.$lead->getKey().'-'.Str::lower(Str::random(6));
        [$names, $qty, $prices] = $this->lines($lead, $total);
        $email = filter_var((string) $lead->email, FILTER_VALIDATE_EMAIL) ? $lead->email : null;

        $session = $this->client($gateway)->createPayment(array_filter([
            'product' => $names,
            'qty' => $qty,
            'price' => $prices,
            'description' => ['Pembelian dari '.$lead->tenant->name],
            'returnUrl' => route('digital.pay.show', $token),
            'cancelUrl' => route('digital.pay.show', $token),
            'notifyUrl' => route('digital.ipaymu.notify', $lead->tenant_id),
            'referenceId' => $reference,
            'buyerName' => $lead->name,
            'buyerEmail' => $email,
            'buyerPhone' => $lead->phone,
            'expired' => $hours,
        ], fn ($v) => filled($v)));

        return DigitalPayment::create([
            'tenant_id' => $lead->tenant_id, 'lead_id' => $lead->getKey(), 'reference_id' => $reference, 'token' => $token,
            'session_id' => $session['session_id'], 'payment_url' => $session['url'], 'amount' => $total,
            'status' => DigitalPayment::PENDING, 'expires_at' => now()->addHours($hours),
        ]);
    }

    /** The catalogue lines of the purchase when they add up to the total, else one line for the whole total. */
    private function lines(Lead $lead, int $total): array
    {
        $items = collect((array) $lead->order_items)->filter(fn ($i) => isset($i['price'], $i['quantity']) && $i['price'] !== null);
        $sum = (int) $items->sum(fn ($i) => (int) $i['price'] * (int) $i['quantity']);

        if ($items->isNotEmpty() && $sum === $total) {
            return [
                $items->map(fn ($i) => mb_substr((string) $i['name'], 0, 100))->values()->all(),
                $items->map(fn ($i) => (string) (int) $i['quantity'])->values()->all(),
                $items->map(fn ($i) => (string) (int) $i['price'])->values()->all(),
            ];
        }

        return [[mb_substr($lead->property?->name ?? 'Pembelian produk digital', 0, 100)], ['1'], [(string) $total]];
    }

    private function client(PaymentGateway $gateway): IpaymuClient
    {
        return $this->client->using((string) $gateway->va, (string) $gateway->api_key, (bool) $gateway->sandbox);
    }

    private function text(string $template, Lead $lead, DigitalPayment $payment): string
    {
        return strtr($template, [
            '{nama}' => $lead->name ?: 'Kak',
            '{produk}' => $lead->property?->name ?? 'produk',
            '{total}' => ShippingQuote::rupiah((int) $payment->amount),
            '{tautan}' => $payment->payment_url,
            '{batas}' => $payment->expires_at ? PaymentInstructions::when($payment->expires_at, $lead->tenant) : '-',
        ]);
    }

    /**
     * Handles an iPaymu callback for this agency whose signature already passed: asks iPaymu for the real status
     * with the agency's own key and applies it once.
     *
     * @return string 'unknown' | 'mismatch' | 'unverified' | DigitalPayment status
     */
    public function handleCallback(Tenant $tenant, PaymentGateway $gateway, array $payload): string
    {
        $reference = (string) ($payload['reference_id'] ?? $payload['referenceId'] ?? '');
        $trxId = (string) ($payload['trx_id'] ?? '');
        $payment = $reference === '' ? null : DigitalPayment::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('reference_id', $reference)->first();
        $context = ['tenant_id' => $tenant->getKey(), 'trx_id' => $trxId, 'reference_id' => $reference];

        if (! $payment || $trxId === '') {
            Log::warning('Callback iPaymu digital ditolak: referensi tidak dikenal', $context);

            return 'unknown';
        }

        try {
            $data = $this->client($gateway)->checkTransaction($trxId);
        } catch (\Throwable $e) {
            Log::warning('Callback iPaymu digital: cek transaksi gagal', $context + ['error' => $e->getMessage()]);

            return 'unverified';
        }

        $price = self::money($data['SubTotal'] ?? null) ?? ((self::money($data['Amount'] ?? null) ?? -1) - (self::money($data['BuyerFee'] ?? $data['AdditionalFee'] ?? null) ?? 0));
        $sameSession = blank($data['SessionId'] ?? null) || blank($payment->session_id) || strcasecmp((string) $data['SessionId'], $payment->session_id) === 0;

        if ((string) ($data['ReferenceId'] ?? '') !== $payment->reference_id || $price !== (int) $payment->amount || ! $sameSession) {
            Log::warning('Callback iPaymu digital ditolak: data tidak cocok', $context + ['amount_expected' => $payment->amount, 'price_api' => $price]);

            return 'mismatch';
        }

        // iPaymu transaction status codes (as for subscriptions): 1, 6 paid; 7 escrow (paid, held by iPaymu); -2 expired.
        $code = is_numeric($data['Status'] ?? null) ? (int) $data['Status'] : null;
        $status = match ($code) {
            1, 6, 7 => DigitalPayment::PAID,
            -2 => DigitalPayment::EXPIRED,
            2, 3, 4, 5 => DigitalPayment::FAILED,
            default => DigitalPayment::PENDING,
        };
        $fee = self::money($data['Fee'] ?? $payload['fee'] ?? null);
        $channel = mb_substr((string) ($payload['via'] ?? $payload['channel'] ?? ''), 0, 60);

        $paidNow = DB::transaction(function () use ($payment, $status, $trxId, $channel, $fee) {
            $locked = DigitalPayment::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->getKey());
            $locked->forceFill(['trx_id' => $trxId, 'last_callback_at' => now()] + ($channel !== '' ? ['channel' => $channel] : []) + ($fee !== null ? ['fee' => $fee] : []));

            // A paid invoice never changes again, so a repeated callback never pays twice.
            if ($locked->status === DigitalPayment::PAID) {
                $locked->save();

                return false;
            }

            $locked->status = $status;
            $locked->paid_at = $status === DigitalPayment::PAID ? now() : null;
            $locked->save();

            return $status === DigitalPayment::PAID;
        });

        if ($paidNow) {
            app(CurrentTenant::class)->run($tenant, fn () => app(DigitalStageAutomation::class)->paidByGateway($payment->refresh()));
        }

        Log::info('Callback iPaymu digital diproses', $context + ['status_api' => $code, 'hasil' => $status]);

        return $paidNow || $payment->refresh()->status === DigitalPayment::PAID ? DigitalPayment::PAID : $status;
    }

    /** Must run inside the tenant: one reminder for an invoice about to expire. */
    public function remind(Tenant $tenant, User $actor): int
    {
        if (! self::applies($tenant)) {
            return 0;
        }

        $sent = 0;
        $due = DigitalPayment::query()->with('lead.property')->where('status', DigitalPayment::PENDING)->whereNull('reminded_at')
            ->whereBetween('expires_at', [now(), now()->addHours(self::REMIND_HOURS_BEFORE)])->get();

        foreach ($due as $payment) {
            $payment->forceFill(['reminded_at' => now()])->save();
            $lead = $payment->lead;

            if ($lead === null || $lead->paid_at !== null) {
                continue;
            }

            $why = $this->whatsapp->deliver($lead, $actor, $this->text(self::REMINDER, $lead, $payment), self::ORIGIN);
            $lead->activities()->create(['type' => 'payment_link', 'body' => $why === null ? 'Pengingat bayar dikirim sistem sebelum tautan bayar kedaluwarsa.' : "Pengingat bayar tidak terkirim ({$why})."]);
            $why === null && $sent++;
        }

        return $sent;
    }

    private static function money(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }
}
