<?php

namespace App\Console\Commands;

use App\Automation\PropertyStageAutomation;
use App\Automation\PropertyStageAutomationSettings;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stages:catch-up {--tenant= : slug agensi properti} {--apply : terapkan; tanpa ini hanya pratinjau}')]
#[Description('Agensi properti: tampilkan (atau terapkan dengan --apply) perpindahan tahap dan tugas yang sudah layak menurut Otomatisasi tahap untuk lead yang ada')]
class AutomateStagesCatchUpCommand extends Command
{
    public function handle(PropertyStageAutomation $automation): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();

        if ($tenant === null || ! PropertyStageAutomationSettings::appliesTo($tenant)) {
            $this->error('Agensi properti tidak ditemukan. Pakai --tenant=<slug>.');

            return self::FAILURE;
        }

        if (! PropertyStageAutomationSettings::of($tenant)->chosen()) {
            $this->warn('Otomatisasi tahap belum dinyalakan untuk agensi ini (Pengaturan & Alat > Otomatisasi tahap > Nyalakan).');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $changes = $automation->catchUp($tenant, $apply);

        $this->table(['Lead', 'Perubahan', 'Alasan'], array_map(fn (array $c) => [$c['lead'], $c['change'], $c['reason']], $changes));
        $this->info(count($changes).' perubahan '.($apply ? 'diterapkan.' : 'akan diterapkan. Jalankan lagi dengan --apply untuk menerapkan.'));

        return self::SUCCESS;
    }
}
