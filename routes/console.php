<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('followups:run')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('plan:check')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping();
