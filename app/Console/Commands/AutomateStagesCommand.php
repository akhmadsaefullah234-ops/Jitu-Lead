<?php

namespace App\Console\Commands;

use App\Automation\DigitalStageAutomation;
use App\Automation\PropertyStageAutomation;
use App\Automation\StageAutomation;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stages:automate')]
#[Description('Pindahkan lead yang sudah waktunya. Toko: selesai setelah resi dikirim, batal bila tidak ada respons atau pembayaran. Properti: sudah survei setelah jadwal lewat, gugur bila klien diam. Digital: tidak jadi bayar setelah N jam')]
class AutomateStagesCommand extends Command
{
    public function handle(StageAutomation $shop, PropertyStageAutomation $property, DigitalStageAutomation $digital): int
    {
        // Each industry's rules only ever see its own agencies.
        Tenant::query()->whereIn('industry', ['retail', 'properti', 'digital'])->each(function (Tenant $tenant) use ($shop, $property, $digital) {
            try {
                $r = match ($tenant->industry) {
                    'retail' => $shop->runScheduled($tenant),
                    'digital' => $digital->runScheduled($tenant),
                    default => $property->runScheduled($tenant),
                };
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");

                return;
            }

            if (array_sum($r) > 0) {
                $this->line(match ($tenant->industry) {
                    'retail' => "{$tenant->slug}: selesai {$r['done']}, batal tanpa respons {$r['no_reply']}, batal tanpa pembayaran {$r['no_payment']}",
                    'digital' => "{$tenant->slug}: tidak jadi bayar {$r['no_payment']}, pengingat bayar {$r['reminded']}",
                    default => "{$tenant->slug}: sudah survei {$r['surveyed']}, gugur tanpa respons {$r['silent']}",
                });
            }
        });

        return self::SUCCESS;
    }
}
