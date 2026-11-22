<?php

namespace App\Console\Commands;

use App\Backup\BackupRunner;
use App\Models\BackupRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:database')]
#[Description('Cadangkan database saja, lalu kirim ke luar server (dijadwalkan tiap BACKUP_DB_EVERY_HOURS jam)')]
class BackupDatabaseCommand extends Command
{
    public function handle(BackupRunner $runner): int
    {
        $run = $runner(BackupRunner::DATABASE);

        foreach ($run->steps as $s) {
            $this->line(sprintf('[%s] %s%s', $s['status'], $s['label'], $s['note'] ? ': '.$s['note'] : ''));
        }
        $this->info('Backup database '.$run->label().($run->database_file ? " ({$run->database_file})" : ''));

        return $run->status === BackupRun::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
