<?php

namespace App\Console\Commands;

use App\Actions\RunFollowUps;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('followups:run')]
#[Description('Kirim follow-up WhatsApp otomatis yang sudah waktunya')]
class RunFollowUpsCommand extends Command
{
    public function handle(RunFollowUps $run): int
    {
        Tenant::query()->each(function (Tenant $tenant) use ($run) {
            try {
                $r = $run($tenant);
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");

                return;
            }

            if (array_sum($r) > 0) {
                $this->line("{$tenant->slug}: terkirim {$r['sent']}, gagal {$r['failed']}, dilewati {$r['skipped']}");
            }
        });

        return self::SUCCESS;
    }
}
