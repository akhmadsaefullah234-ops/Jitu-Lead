<?php

namespace App\Console\Commands;

use App\Billing\PlanLimits;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plan:list')]
#[Description('Daftar agensi dengan paket, status, tanggal berakhir, dan pemakaian AI')]
class PlanListCommand extends Command
{
    public function handle(): int
    {
        $rows = Tenant::query()->orderBy('name')->get()->map(function (Tenant $tenant) {
            $limits = PlanLimits::for($tenant);
            $sub = $limits->subscription();
            $ai = $limits->limit('ai');

            return [
                $tenant->slug, $tenant->name,
                $sub->status === 'trial' ? 'trial (setara '.$sub->effectivePlanKey().')' : ($sub->plan ?? '-'),
                $sub->status,
                $sub->endsAt()?->timezone(config('app.timezone'))->format('Y-m-d') ?? '-',
                $limits->usage('ai').' / '.($ai ?? '∞'),
            ];
        });

        $this->table(['Slug', 'Agensi', 'Paket', 'Status', 'Berakhir', 'Balasan AI'], $rows);

        return self::SUCCESS;
    }
}
