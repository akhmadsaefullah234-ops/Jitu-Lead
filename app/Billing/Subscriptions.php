<?php

namespace App\Billing;

use App\Enums\Role;
use App\Mail\SubscriptionEnding;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

/** Changes to an agency's subscription made by the platform owner or the daily check. */
class Subscriptions
{
    public static function find(string $key): ?Tenant
    {
        return Tenant::query()->where('slug', $key)->when(ctype_digit($key), fn ($q) => $q->orWhereKey((int) $key))->first();
    }

    /** Activates a plan for $months months, extending the current period when it is still running. */
    public function activate(Tenant $tenant, string $plan, string $cycle, int $months): Subscription
    {
        $sub = $tenant->currentSubscription();
        $running = $sub->status === Subscription::ACTIVE && $sub->plan === $plan && $sub->current_period_end?->isFuture();
        $start = $running ? $sub->current_period_end : now();

        $sub->update([
            'plan' => $plan, 'billing_cycle' => $cycle, 'status' => Subscription::ACTIVE,
            'current_period_start' => $running ? $sub->current_period_start : $start,
            'current_period_end' => $start->copy()->addMonthsNoOverflow($months),
            'grace_ends_at' => null, 'reminder_sent_for' => null,
        ]);

        SubscriptionRequest::query()->where('tenant_id', $tenant->getKey())->where('status', SubscriptionRequest::PENDING)
            ->update(['status' => SubscriptionRequest::APPROVED, 'handled_at' => now()]);

        return $sub->refresh();
    }

    public function addAddon(Tenant $tenant, string $kind, int $qty): Subscription
    {
        $sub = $tenant->currentSubscription();
        $column = 'addon_'.$kind;
        $sub->update([$column => max(0, $sub->{$column} + $qty)]);

        return $sub->refresh();
    }

    /**
     * Daily pass: expired trials and periods become past_due (with a grace
     * period), then read_only; and a reminder email goes out before the end.
     *
     * @return array{past_due: int, read_only: int, reminded: int}
     */
    public function dailyCheck(): array
    {
        $result = ['past_due' => 0, 'read_only' => 0, 'reminded' => 0];
        $grace = (int) config('plans.grace_days');
        $remind = (int) config('plans.reminder_days');

        Subscription::query()->with('tenant')->get()->each(function (Subscription $sub) use (&$result, $grace, $remind) {
            if ($sub->tenant === null) {
                return;
            }

            if (in_array($sub->status, [Subscription::TRIAL, Subscription::ACTIVE], true) && $sub->endsAt()?->isPast()) {
                // A trial has no payment to wait for, so by default it goes straight to read-only until a plan is chosen.
                $days = $sub->status === Subscription::TRIAL ? (int) config('plans.trial_grace_days') : $grace;
                $sub->update(['status' => Subscription::PAST_DUE, 'grace_ends_at' => $sub->endsAt()->copy()->addDays($days)]);
                $result['past_due']++;
            }

            if ($sub->status === Subscription::PAST_DUE && ($sub->grace_ends_at ?? now())->isPast()) {
                $sub->update(['status' => Subscription::READ_ONLY]);
                $result['read_only']++;
            }

            $end = $sub->endsAt();

            if (in_array($sub->status, [Subscription::TRIAL, Subscription::ACTIVE], true) && $end !== null
                && $end->isFuture() && $end->lte(now()->addDays($remind))
                && ($sub->reminder_sent_for === null || ! $sub->reminder_sent_for->equalTo($end))) {
                $this->remind($sub);
                $sub->update(['reminder_sent_for' => $end]);
                $result['reminded']++;
            }
        });

        return $result;
    }

    private function remind(Subscription $sub): void
    {
        $admins = $sub->tenant->activeUsers()->wherePivot('role', Role::Admin->value)->get();

        foreach ($admins as $admin) {
            Mail::to($admin->email)->send(new SubscriptionEnding($sub->tenant, $sub));
        }
    }
}
