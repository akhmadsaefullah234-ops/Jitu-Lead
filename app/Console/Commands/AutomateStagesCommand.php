<?php

namespace App\Console\Commands;

use App\Automation\PropertyStageAutomation;
use App\Automation\StageAutomation;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stages:automate')]
#[Description('Pindahkan lead yang sudah waktunya. Toko: selesai setelah resi dikirim, batal bila tidak ada respons atau pembayaran. Properti: sudah survei setelah jadwal lewat, gugur bila klien diam')]
class AutomateStagesCommand extends Command
{
    public function handle(StageAutomation $shop, PropertyStageAutomation $property): int
    {
        // Each industry's rules only ever see its own agencies.
        Tenant::query()->whereIn('industry', ['retail', 'properti'])->each(function (Tenant $tenant) use ($shop, $property) {
            try {
                $r = $tenant->industry === 'retail' ? $shop->runScheduled($tenant) : $property->runScheduled($tenant);
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");

                return;
            }

            if (array_sum($r) > 0) {
                $this->line($tenant->industry === 'retail'
                    ? "{$tenant->slug}: selesai {$r['done']}, batal tanpa respons {$r['no_reply']}, batal tanpa pembayaran {$r['no_payment']}"
                    : "{$tenant->slug}: sudah survei {$r['surveyed']}, gugur tanpa respons {$r['silent']}");
            }
        });

        return self::SUCCESS;
    }
}
