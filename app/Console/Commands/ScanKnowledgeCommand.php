<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Privacy\KnowledgeScan;
use App\Support\CurrentTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:scan-knowledge {--tenant= : slug satu agensi} {--all : juga catatan yang sudah nonaktif} {--deactivate : nonaktifkan catatan yang ditandai}')]
#[Description('Tandai catatan pengetahuan AI (semua agensi) yang memuat data pribadi pembeli: nomor HP, alamat, nama setelah "saya ... mau order"')]
class ScanKnowledgeCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        $total = 0;

        Tenant::query()->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->orderBy('id')
            ->each(function (Tenant $tenant) use ($current, &$total) {
                $current->run($tenant, function () use ($tenant, &$total) {
                    foreach (KnowledgeScan::flagged(! $this->option('all')) as ['item' => $item, 'findings' => $findings]) {
                        $total++;
                        $this->line(sprintf('%s #%d [%s%s] %s', $tenant->slug, $item->getKey(), $item->source, $item->active ? '' : ', nonaktif', str($item->title)->limit(60)));

                        foreach ($findings as $finding) {
                            $this->line('    - '.str($finding)->limit(120));
                        }

                        if ($this->option('deactivate') && $item->active) {
                            $item->update(['active' => false]);
                            $this->line('    => dinonaktifkan');
                        }
                    }
                });
            });

        $this->info($total === 0 ? 'Tidak ada catatan pengetahuan yang memuat data pribadi.' : "{$total} catatan memuat data pribadi pembeli.");

        return self::SUCCESS;
    }
}
