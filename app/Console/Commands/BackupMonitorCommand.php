<?php

namespace App\Console\Commands;

use App\Backup\BackupAlerts;
use App\Models\BackupRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Hourly: when no database dump has passed its check for jitu.backup.stale_hours, the owner gets a push notification
 * and an email, again every 6 hours while it lasts. A fresh install with no backup run yet stays quiet.
 */
#[Signature('backup:monitor')]
#[Description('Beri tahu pemilik bila tidak ada cadangan database valid lebih dari BACKUP_STALE_HOURS jam')]
class BackupMonitorCommand extends Command
{
    public const ALERTED = 'backup.stale_alerted_at';

    public const REPEAT_HOURS = 6;

    public function handle(): int
    {
        $hours = max(1, (int) config('jitu.backup.stale_hours', 26));
        $last = BackupRun::latestValidDatabase();
        $since = now()->subHours($hours);

        $stale = $last !== null
            ? $last->finished_at->lt($since)
            : BackupRun::query()->where('started_at', '<', $since)->exists();

        if (! $stale) {
            Cache::forget(self::ALERTED);
            $this->info($last ? 'Cadangan database valid terakhir: '.$last->finished_at->timezone(config('app.timezone'))->format('d M Y H:i') : 'Belum ada backup (instalasi baru).');

            return self::SUCCESS;
        }

        $this->warn("Tidak ada cadangan database valid lebih dari {$hours} jam.");

        if (Cache::has(self::ALERTED)) {
            return self::SUCCESS;
        }

        Cache::put(self::ALERTED, now()->getTimestamp(), now()->addHours(self::REPEAT_HOURS));
        BackupAlerts::stale($last, $hours);
        $this->line('Pemilik diberi tahu (push dan email).');

        return self::SUCCESS;
    }
}
