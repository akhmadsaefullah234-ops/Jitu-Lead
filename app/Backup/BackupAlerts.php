<?php

namespace App\Backup;

use App\Jobs\SendPushAlert;
use App\Mail\BackupFailed;
use App\Mail\BackupStale;
use App\Models\BackupRun;
use App\Models\User;
use App\Push\PushTransport;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the platform owner that backups are failing, by email (SUPPORT_EMAIL) and by push notification to every super
 * admin's devices. The push is sent at once, not queued, so it still arrives when the queue worker is the problem.
 * One channel failing never stops the other.
 */
final class BackupAlerts
{
    public static function failed(BackupRun $run): void
    {
        self::email(new BackupFailed($run));
        self::push(
            $run->kind === BackupRunner::DATABASE ? 'Backup database GAGAL' : 'Backup harian GAGAL',
            (string) ($run->message ?: 'Lihat riwayat backup'),
            'backup-failed',
        );
    }

    public static function stale(?BackupRun $lastValid, int $hours): void
    {
        self::email(new BackupStale($lastValid, $hours));
        self::push(
            "Tidak ada backup database valid > {$hours} jam",
            $lastValid ? 'Terakhir: '.$lastValid->finished_at->timezone(config('app.timezone'))->format('d M Y H:i') : 'Belum pernah ada',
            'backup-stale',
        );
    }

    private static function email(object $mailable): void
    {
        if (! ($to = config('jitu.support_email'))) {
            return;
        }

        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function push(string $title, string $body, string $tag): void
    {
        try {
            if (! app(PushTransport::class)->enabled()) {
                return;
            }

            $ids = User::query()->where('is_super_admin', true)->pluck('id')->all();

            if ($ids !== []) {
                SendPushAlert::dispatchSync($ids, ['title' => $title, 'body' => mb_strimwidth($body, 0, 180, '…'), 'url' => url('/admin/backup-runs'), 'tag' => $tag]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
