<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('followups:run')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('leads:reassign')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('plan:check')->dailyAt('01:00')->withoutOverlapping();
// After plan:check, so trials that just ended are already read-only.
Schedule::command('trial-emails:send')->dailyAt('09:00')->timezone('Asia/Jakarta')->withoutOverlapping();
// Promo campaigns start at the hour the owner picks, so they are checked often; the daily cap is shared with the trial emails.
Schedule::command('campaigns:send')->everyTenMinutes()->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping();
