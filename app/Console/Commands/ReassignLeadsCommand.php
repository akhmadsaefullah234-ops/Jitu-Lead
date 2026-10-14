<?php

namespace App\Console\Commands;

use App\Actions\ReassignLead;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leads:reassign')]
#[Description('Pindahkan lead yang belum ditanggapi ke agen lain (agensi dengan pemindahan otomatis)')]
class ReassignLeadsCommand extends Command
{
    public function handle(ReassignLead $reassign): int
    {
        Tenant::query()->where('lead_reassign_mode', 'auto')->each(function (Tenant $tenant) use ($reassign) {
            try {
                $moved = $reassign->stale($tenant);
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");

                return;
            }

            if ($moved > 0) {
                $this->line("{$tenant->slug}: {$moved} lead dipindah");
            }
        });

        return self::SUCCESS;
    }
}
