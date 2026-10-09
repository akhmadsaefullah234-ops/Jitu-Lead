<?php

namespace App\Console\Commands;

use App\Billing\Subscriptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plan:addon {tenant : slug atau id agensi} {kind : ai, user, atau wa} {--qty=1 : jumlah tambahan (negatif untuk mengurangi)}')]
#[Description('Tambah atau kurangi add-on (balasan AI, pengguna, nomor WhatsApp) sebuah agensi')]
class PlanAddonCommand extends Command
{
    public function handle(Subscriptions $subscriptions): int
    {
        $tenant = Subscriptions::find($this->argument('tenant'));
        $kind = $this->argument('kind');

        if (! $tenant) {
            $this->error('Agensi tidak ditemukan.');

            return self::FAILURE;
        }

        if (! array_key_exists($kind, config('plans.addons'))) {
            $this->error('Jenis add-on: '.implode(', ', array_keys(config('plans.addons'))));

            return self::FAILURE;
        }

        $sub = $subscriptions->addAddon($tenant, $kind, (int) $this->option('qty'));
        $this->info("{$tenant->name}: add-on {$kind} sekarang {$sub->{'addon_'.$kind}}.");

        return self::SUCCESS;
    }
}
