<?php

namespace App\Campaigns;

use App\Enums\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a promo campaign may write to: people who agreed to be contacted about offers, did not unsubscribe and
 * verified their email; then narrowed to a segment by the agencies they run.
 */
class CampaignAudience
{
    /** @return Builder<User> */
    public static function query(string $segment): Builder
    {
        $query = self::reachable(User::query());

        $trial = fn (bool $running) => fn (Builder $s) => $s->whereNull('current_period_end')->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', $running ? '>' : '<=', now());

        return match ($segment) {
            'trial_active' => $query->whereHas('tenants', fn (Builder $t) => self::adminOf($t)->whereHas('subscription', $trial(true))),
            'trial_expired' => $query->whereHas('tenants', fn (Builder $t) => self::adminOf($t)->whereHas('subscription', $trial(false)))
                ->whereDoesntHave('tenants', fn (Builder $t) => self::paying($t)),
            'subscribers' => $query->whereHas('tenants', fn (Builder $t) => self::paying($t)),
            default => $query,
        };
    }

    public static function count(string $segment): int
    {
        return self::query($segment)->count();
    }

    /** May this person still get a campaign email? */
    public static function reachable(Builder $users): Builder
    {
        return $users->whereNotNull('contact_consent_at')->whereNull('trial_emails_unsubscribed_at')->whereNotNull('email_verified_at');
    }

    /** True when one of the person's agencies pays for a plan now. */
    public static function subscribed(User $user): bool
    {
        return $user->tenants()->where(fn (Builder $t) => self::paying($t))->exists();
    }

    private static function adminOf(Builder $tenants): Builder
    {
        return $tenants->where('tenant_user.status', 'active')->where('tenant_user.role', Role::Admin->value)->where('tenants.status', '!=', 'suspended');
    }

    private static function paying(Builder $tenants): Builder
    {
        return $tenants->where('tenant_user.status', 'active')
            ->whereHas('subscription', fn (Builder $s) => $s->whereNotNull('current_period_end')->where('status', Subscription::ACTIVE));
    }
}
