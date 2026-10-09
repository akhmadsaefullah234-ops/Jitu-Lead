<?php

namespace App\Filament\Pages;

use App\Billing\PlanLimits;
use App\Billing\PlanRequests;
use App\Enums\Role;
use App\Models\SubscriptionRequest;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The agency admin's view of the plan: what is used against each limit, the
 * three plans, and a request button. Payment is a manual transfer for now.
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
            'bankInfo' => config('jitu.billing_bank_info'),
            'contact' => config('jitu.billing_contact'),
        ];
    }
}
