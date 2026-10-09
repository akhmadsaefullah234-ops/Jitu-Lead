<?php

namespace App\Console\Commands;

use App\Billing\Subscriptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plan:set {tenant : slug atau id agensi} {plan : mandiri, tim, atau agensi} {--cycle=monthly : monthly atau yearly} {--months= : lama periode dalam bulan (bawaan 1, atau 12 untuk yearly)}')]
#[Description('Aktifkan paket untuk sebuah agensi setelah pembayaran dikonfirmasi')]
class PlanSetCommand extends Command
{
    public function handle(Subscriptions $subscriptions): int
    {
        $tenant = Subscriptions::find($this->argument('tenant'));
        $plan = $this->argument('plan');
        $cycle = $this->option('cycle');

        if (! $tenant) {
            $this->error('Agensi tidak ditemukan.');

            return self::FAILURE;
        }

        if (! array_key_exists($plan, config('plans.plans'))) {
            $this->error('Paket tidak dikenal. Pilihan: '.implode(', ', array_keys(config('plans.plans'))));

            return self::FAILURE;
        }

        if (! in_array($cycle, ['monthly', 'yearly'], true)) {
            $this->error('--cycle harus monthly atau yearly.');

            return self::FAILURE;
        }

        $months = $this->option('months') !== null ? (int) $this->option('months') : ($cycle === 'yearly' ? 12 : 1);

        if ($months < 1 || $months > 36) {
            $this->error('--months harus 1 sampai 36.');

            return self::FAILURE;
        }

        $sub = $subscriptions->activate($tenant, $plan, $cycle, $months);
        $this->info("{$tenant->name}: paket {$plan} aktif sampai {$sub->current_period_end->timezone(config('app.timezone'))->format('Y-m-d')}.");

        return self::SUCCESS;
    }
}
