<?php

namespace App\Console\Commands;

use App\Billing\Subscriptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plan:check')]
#[Description('Pemeriksaan harian langganan: jatuh tempo, masa tenggang, hanya-baca, dan email pengingat')]
class PlanCheckCommand extends Command
{
    public function handle(Subscriptions $subscriptions): int
    {
        $r = $subscriptions->dailyCheck();
        $this->info("Jatuh tempo: {$r['past_due']}, hanya-baca: {$r['read_only']}, pengingat terkirim: {$r['reminded']}");

        return self::SUCCESS;
    }
}
