<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\SupportThread;
use App\Models\Tenant;

/** Numbers for the platform owner's dashboard. Reads across all agencies, so it never goes through the tenant scope. */
class PlatformStats
{
    /**
     * @return array{tenants: int, trial: int, trial_ended: int, active: int, past_due: int, read_only: int, suspended: int, mrr: int, new_7d: int, pending_requests: int, open_chats: int}
     */
    public static function summary(): array
    {
        $count = ['trial' => 0, 'trial_ended' => 0, 'active' => 0, 'past_due' => 0, 'read_only' => 0];
        $mrr = 0;

        Subscription::query()->each(function (Subscription $sub) use (&$count, &$mrr) {
            $status = $sub->effectiveStatus();

            if ($status === Subscription::READ_ONLY && $sub->neverPaid()) {
                $count['trial_ended']++;
            } else {
                $count[$status] = ($count[$status] ?? 0) + 1;
            }

            if ($status === Subscription::ACTIVE && $sub->plan) {
                $mrr += static::monthlyValue($sub);
            }
        });

        return $count + [
            'tenants' => Tenant::query()->count(),
            'suspended' => Tenant::query()->where('status', 'suspended')->count(),
            'mrr' => $mrr,
            'new_7d' => Tenant::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'pending_requests' => SubscriptionRequest::query()->where('status', SubscriptionRequest::PENDING)->count(),
            'open_chats' => static::unansweredChats(),
        ];
    }

    /** What a paid agency is worth per month (a yearly price is spread over 12 months). Add-ons are not counted. */
    public static function monthlyValue(Subscription $sub): int
    {
        $price = config("plans.plans.{$sub->plan}.price.{$sub->billing_cycle}");

        if ($price === null) {
            return 0;
        }

        return (int) round($sub->billing_cycle === 'yearly' ? $price / 12 : $price);
    }

    public static function unansweredChats(): int
    {
        return SupportThread::query()->withoutGlobalScopes()->where('status', SupportThread::OPEN)->where('staff_unread', true)->count();
    }

    /** New agencies per day for the last $days days, oldest first, zero-filled. @return array<string, int> */
    public static function signupsPerDay(int $days = 30): array
    {
        $rows = Tenant::query()->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())->pluck('created_at')
            ->countBy(fn ($at) => $at->timezone(config('app.timezone'))->format('Y-m-d'));

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->timezone(config('app.timezone'))->format('Y-m-d');
            $series[$day] = (int) ($rows[$day] ?? 0);
        }

        return $series;
    }
}
