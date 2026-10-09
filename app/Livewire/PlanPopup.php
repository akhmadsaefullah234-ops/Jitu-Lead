<?php

namespace App\Livewire;

use App\Billing\PlanRequests;
use App\Enums\Role;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The "choose a plan" pop-up. It shows when a trial has ended, a paid period is
 * overdue, or the end is a few days away. An agency admin picks a plan right
 * here (which sends the request and shows the transfer details); other members
 * are told who to ask. "Nanti" hides it for a while, not for good.
 */
class PlanPopup extends Component
{
    public const SNOOZE = ['ending' => 86400, 'past_due' => 14400, 'read_only' => 3600, 'trial_ended' => 3600];

    public string $cycle = 'monthly';

    public ?int $requestId = null;

    private function tenant(): ?Tenant
    {
        return app(CurrentTenant::class)->get();
    }

    private function isAdmin(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    /** @return 'ending'|'past_due'|'read_only'|'trial_ended'|null */
    public function state(): ?string
    {
        $tenant = $this->tenant();

        if ($tenant === null || ! auth()->check()) {
            return null;
        }

        $sub = $tenant->currentSubscription();
        $status = $sub->effectiveStatus();

        return match (true) {
            $status === Subscription::READ_ONLY => $sub->neverPaid() ? 'trial_ended' : 'read_only',
            $status === Subscription::PAST_DUE => 'past_due',
            in_array($status, [Subscription::TRIAL, Subscription::ACTIVE], true) && ($sub->daysLeft() ?? 99) <= 3 && $sub->endsAt() !== null => 'ending',
            default => null,
        };
    }

    private function snoozed(): bool
    {
        return (int) session('plan_popup_until', 0) > time();
    }

    public function snooze(): void
    {
        session(['plan_popup_until' => time() + (self::SNOOZE[$this->state()] ?? 3600)]);
        $this->requestId = null;
    }

    public function choose(string $plan): void
    {
        abort_unless($this->isAdmin(), 403);

        $request = PlanRequests::create($this->tenant(), auth()->user(), $plan, $this->cycle);

        $this->requestId = $request?->getKey();
    }

    public function render(): View
    {
        $state = $this->state();
        $tenant = $this->tenant();
        $show = $state !== null && ($this->requestId !== null || ! $this->snoozed());

        return view('livewire.plan-popup', [
            'show' => $show,
            'state' => $state,
            'sub' => $show ? $tenant->currentSubscription() : null,
            'admin' => $show && $this->isAdmin(),
            'admins' => $show && ! $this->isAdmin() ? $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->pluck('name')->all() : [],
            'plans' => config('plans.plans'),
            'request' => $this->requestId ? SubscriptionRequest::query()->whereKey($this->requestId)->first() : null,
            'bankInfo' => config('jitu.billing_bank_info'),
            'contact' => config('jitu.billing_contact'),
            'pageUrl' => $show && $this->isAdmin() ? SubscriptionPage::getUrl() : null,
        ]);
    }
}
