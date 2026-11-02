<?php

namespace App\Console\Commands;

use App\Automation\StageAutomation;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stages:automate')]
#[Description('Pindahkan lead toko yang sudah waktunya: selesai setelah resi dikirim, batal bila tidak ada respons atau pembayaran')]
class AutomateStagesCommand extends Command
{
    public function handle(StageAutomation $automation): int
    {
        Tenant::query()->where('industry', 'retail')->each(function (Tenant $tenant) use ($automation) {
            try {
                $r = $automation->runScheduled($tenant);
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");

                return;
            }

            if (array_sum($r) > 0) {
                $this->line("{$tenant->slug}: selesai {$r['done']}, batal tanpa respons {$r['no_reply']}, batal tanpa pembayaran {$r['no_payment']}");
            }
        });

        return self::SUCCESS;
    }
}
