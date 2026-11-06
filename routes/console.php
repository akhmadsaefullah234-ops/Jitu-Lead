<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('followups:run')->everyFiveMinutes()->withoutOverlapping();
// Form welcomes: each has its own delayed job; this catches any whose job was lost or that waited for 05:00.
Schedule::command('welcome:send')->everyMinute()->withoutOverlapping();
Schedule::command('shipping:notices')->everyMinute()->withoutOverlapping();
Schedule::command('shipping:track')->everyTwoHours()->withoutOverlapping();
// Print runs are kept 30 days to download again, each parcel's label 90 days (config/carriers.php).
Schedule::command('shipping:labels-prune')->hourly()->withoutOverlapping();
// "Resi otomatis": leads whose wait is over are booked; each agency's admins get a morning summary.
Schedule::command('shipping:auto-resi')->everyMinute()->withoutOverlapping();
// Parcels still waiting for a tracking number are matched with Everpro's order list again (its TRD… order numbers).
Schedule::command('shipping:fix-everpro-orders --waiting --apply')->everyTenMinutes()->withoutOverlapping();
// Daily/weekly/monthly reports to owners and admins at the time each agency (or person) picked, in its time zone.
Schedule::command('reports:daily')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('leads:reassign')->everyFiveMinutes()->withoutOverlapping();
// Shops: delivered, silent and unpaid leads move by themselves (Pengaturan > Otomatisasi tahap).
Schedule::command('stages:automate')->hourly()->withoutOverlapping();
Schedule::command('plan:check')->dailyAt('01:00')->withoutOverlapping();
// After plan:check, so trials that just ended are already read-only.
Schedule::command('trial-emails:send')->dailyAt('09:00')->timezone('Asia/Jakarta')->withoutOverlapping();
// Promo campaigns start at the hour the owner picks, so they are checked often; the daily cap is shared with the trial emails.
Schedule::command('campaigns:send')->everyTenMinutes()->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping();
// QR gateway numbers: admins are emailed when one has been down 5 minutes, and when it is back (App\WhatsApp\ChannelAlerts).
Schedule::command('whatsapp:watch')->everyFiveMinutes()->withoutOverlapping();
// WhatsApp Business labels waiting for the label to exist on the account (tried for 7 days).
Schedule::command('whatsapp:labels-retry')->everyTenMinutes()->withoutOverlapping();
