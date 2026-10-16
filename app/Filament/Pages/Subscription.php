<?php

namespace App\Filament\Pages;

use App\Billing\Ipaymu\IpaymuPayments;
use App\Billing\PlanLimits;
use App\Billing\PlanRequests;
use App\Enums\Role;
use App\Models\IpaymuPayment;
use App\Models\SubscriptionRequest;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The agency admin's view of the plan: what is used against each limit, the
 * three plans, and a request button. Payment is by iPaymu (when configured) or a manual transfer.
 */
class Subscription extends Page
{
    protected string $view = 'filament.pages.subscription';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Langganan';

    protected static ?string $title = 'Langganan';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 1;

    public string $cycle = 'monthly';

    public static function canAccess(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    private function limits(): PlanLimits
    {
        return PlanLimits::for(app(CurrentTenant::class)->get());
    }

    public function pending(): ?SubscriptionRequest
    {
        return SubscriptionRequest::query()->where('tenant_id', app(CurrentTenant::class)->id())
            ->where('status', SubscriptionRequest::PENDING)->latest('id')->first();
    }

    /** Creates a request for the plan and cycle shown on screen; replaces an older pending one. */
    public function choose(string $plan): void
    {
        abort_unless(static::canAccess(), 403);

        if (PlanRequests::create(app(CurrentTenant::class)->get(), auth()->user(), $plan, $this->cycle) === null) {
            return;
        }

        Notification::make()->title('Permintaan paket dibuat')->body('Ikuti instruksi transfer di halaman ini. Paket aktif setelah pembayaran kami konfirmasi.')->success()->send();
    }

    /** The newest payment of the pending request, or of the last request when none is pending (so a just-paid one shows). */
    public function latestPayment(): ?IpaymuPayment
    {
        $tenantId = app(CurrentTenant::class)->id();
        $request = $this->pending() ?? SubscriptionRequest::query()->where('tenant_id', $tenantId)->has('payments')->latest('id')->first();

        return $request?->payments()->latest('id')->first();
    }

    /** Opens (or reuses) the iPaymu payment page for the pending request. */
    public function pay()
    {
        abort_unless(static::canAccess(), 403);

        $request = $this->pending();
        $payments = app(IpaymuPayments::class);

        if (! $request || ! $payments->enabled()) {
            return;
        }

        try {
            $payment = $payments->start($request, auth()->user());
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Pembayaran otomatis belum bisa dibuka')->body('Coba lagi sebentar lagi, atau pakai transfer manual di bawah.')->danger()->send();

            return;
        }

        return redirect()->away($payment->payment_url);
    }

    public function cancelRequest(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->pending()?->update(['status' => SubscriptionRequest::CANCELLED, 'handled_at' => now()]);
    }

    public function getViewData(): array
    {
        $limits = $this->limits();
        $rows = [];

        foreach (PlanLimits::KEYS as $key) {
            $rows[] = ['label' => config("plans.limit_labels.$key"), 'usage' => $limits->usage($key), 'limit' => $limits->limit($key), 'percent' => $limits->percent($key)];
        }

        return [
            'sub' => $limits->subscription(),
            'currentPlan' => $limits->plan(),
            'rows' => $rows,
            'plans' => config('plans.plans'),
            'addons' => config('plans.addons'),
            'limitLabels' => config('plans.limit_labels'),
            'featureLabels' => config('plans.feature_labels'),
            'pending' => $this->pending(),
            'payment' => $this->latestPayment(),
            'ipaymuOn' => app(IpaymuPayments::class)->enabled(),
            'bankInfo' => config('jitu.billing_bank_info'),
            'contact' => config('jitu.billing_contact'),
        ];
    }
}
