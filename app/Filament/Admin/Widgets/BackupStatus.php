<?php

namespace App\Filament\Admin\Widgets;

use App\Models\BackupRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Last backup at a glance. Red when it failed or none ran in the last 36 hours. */
class BackupStatus extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '300s';

    protected function getStats(): array
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
}
