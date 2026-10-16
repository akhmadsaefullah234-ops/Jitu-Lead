<?php

namespace App\Retention;

use App\Models\EmailCampaignSend;
use App\Models\TrialEmailLog;

/**
 * The one daily cap (TRIAL_EMAILS_DAILY_LIMIT) shared by the trial series and the promo campaigns, so together
 * they stay inside the mail provider's free quota. The day is the WIB calendar day.
 */
class DailyEmailQuota
{
    public static function usedToday(): int
    {
        $since = now()->timezone(config('app.timezone'))->startOfDay();

        return (int) TrialEmailLog::query()->where('created_at', '>=', $since)->sum('recipients')
            + EmailCampaignSend::query()->where('queued_at', '>=', $since)->count();
    }

    public static function remaining(): int
    {
        return max(0, (int) config('trial_emails.daily_limit') - self::usedToday());
    }
}
