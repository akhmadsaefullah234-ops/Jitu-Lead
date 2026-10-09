<?php

namespace App\Console\Commands;

use App\Backup\BackupRunner;
use App\Models\BackupRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:run')]
#[Description('Cadangkan database, unggahan, dan sesi WhatsApp gateway (dijadwalkan harian 02:00 WIB)')]
class BackupRunCommand extends Command
{
    public function handle(BackupRunner $runner): int
    {
        $run = $runner();

        foreach ($run->steps as $s) {
            $this->line(sprintf('[%s] %s%s', $s['status'], $s['label'], $s['note'] ? ': '.$s['note'] : ''));
        }
        $this->info('Backup '.$run->label().($run->folder ? " ({$run->folder})" : ''));

        return $run->status === BackupRun::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
