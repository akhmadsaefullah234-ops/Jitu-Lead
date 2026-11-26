<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Shipping\Shipments\ParcelFollowUps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shipping:parcel-follow-ups')]
#[Description('Kirim follow-up harian untuk paket gagal antar (agensi yang menyalakannya di Otomatisasi tahap)')]
class ParcelFollowUpsCommand extends Command
{
    public function handle(ParcelFollowUps $followUps): int
    {
        Tenant::query()->where('industry', 'retail')->whereNotNull('parcel_follow_up')->each(function (Tenant $tenant) use ($followUps) {
            try {
                $r = $followUps->run($tenant);
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");

                return;
            }

            if (array_sum($r) > 0) {
                $this->line("{$tenant->slug}: terkirim {$r['sent']}, berhenti {$r['stopped']}, tertahan {$r['blocked']}");
            }
        });

        return self::SUCCESS;
    }
}
