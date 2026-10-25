<?php

namespace App\Billing;

use App\Enums\Role;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Filament\Pages\TrialEnded;
use App\Models\Tenant;
use App\Support\CurrentTenant;

/**
 * An agency whose trial ran out without ever paying is locked: its members only see the plan and how to renew,
 * until a plan is paid. Leads, chats and web forms keep coming in and are kept; they show again once it is paid.
 */
class TrialLock
{
    public static function applies(?Tenant $tenant = null): bool
    {
        $tenant ??= app(CurrentTenant::class)->get();

        if ($tenant === null) {
            return false;
        }

        $sub = $tenant->currentSubscription();

        return $sub->isReadOnly() && $sub->neverPaid();
    }

    /** The one page a member of a locked agency lands on: the plans for its admins, an explanation for the others. */
    public static function homeUrl(): string
    {
        return self::isAdmin() ? SubscriptionPage::getUrl() : TrialEnded::getUrl();
    }

    /** @return list<class-string> the pages a locked agency's member may still open */
    public static function openPages(): array
    {
        return self::isAdmin() ? [SubscriptionPage::class, TrialEnded::class] : [TrialEnded::class];
    }

    private static function isAdmin(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }
}
