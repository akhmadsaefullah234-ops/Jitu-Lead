<?php

namespace App\Filament\Admin\Widgets;

use App\Backup\Shell;
use App\Models\BackupRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Backups at a glance: the last nightly run (red when it failed or none ran in 36 hours), the last database dump that
 * passed its check (red after jitu.backup.stale_hours) and how full the backup disk is (amber above 70%).
 */
class BackupStatus extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '300s';

    protected function getStats(): array
    {
        return [...$this->nightly(), $this->database(), $this->disk()];
    }

    /** @return list<Stat> */
    private function nightly(): array
    {
        $run = BackupRun::latestRun();

        if ($run === null) {
            return [Stat::make('Backup terakhir', 'Belum pernah')->description('Jalankan: php artisan backup:run, dan pastikan scheduler aktif')->color('danger')];
        }

        $stale = $run->started_at->lt(now()->subHours(36));
        $color = $run->status === BackupRun::FAILED || $stale ? 'danger' : ($run->status === BackupRun::WARNING ? 'warning' : 'success');

        return [
            Stat::make('Backup terakhir', $run->label())
                ->description($run->started_at->timezone(config('app.timezone'))->format('d M Y H:i').($stale ? ' (sudah lebih dari 36 jam!)' : ''))->color($color),
            Stat::make('Ukuran', number_format($run->size_bytes / 1048576, 1, ',', '.').' MB')
                ->description($run->message ?: 'Semua langkah berhasil')->color($color),
        ];
    }

    private function database(): Stat
    {
        $hours = (int) config('jitu.backup.stale_hours', 26);
        $run = BackupRun::latestValidDatabase();

        if ($run === null) {
            return Stat::make('Database valid terakhir', 'Belum ada')->description('Jalankan: php artisan backup:database')->color('danger');
        }

        $stale = $run->finished_at->lt(now()->subHours($hours));

        return Stat::make('Database valid terakhir', $run->finished_at->timezone(config('app.timezone'))->format('d M Y H:i'))
            ->description(number_format((int) $run->database_bytes / 1048576, 1, ',', '.').' MB'.($stale ? " (lebih dari {$hours} jam!)" : ''))
            ->color($stale ? 'danger' : 'success');
    }

    private function disk(): Stat
    {
        $free = app(Shell::class)->freePercent((string) config('jitu.backup.path'))
            ?? app(Shell::class)->freePercent(storage_path());

        if ($free === null) {
            return Stat::make('Disk server', 'Tidak terbaca')->color('gray');
        }

        $used = round(100 - $free, 1);
        $warn = $used > (float) config('jitu.backup.disk_warn_used_percent', 70);

        return Stat::make('Disk server', str_replace('.', ',', (string) $used).'% terpakai')
            ->description($warn ? 'Lebih dari '.config('jitu.backup.disk_warn_used_percent', 70).'%: kosongkan disk atau kurangi masa simpan cadangan' : 'Masih lega')
            ->color($warn ? 'warning' : 'success');
    }
}
