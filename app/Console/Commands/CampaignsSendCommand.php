<?php

namespace App\Console\Commands;

use App\Campaigns\CampaignRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campaigns:send')]
#[Description('Kirim email kampanye promo yang sudah waktunya (lewat antrean, berbagi batas harian dengan email percobaan)')]
class CampaignsSendCommand extends Command
{
    public function handle(CampaignRunner $runner): int
    {
        $r = $runner->run();
        $this->info("Email kampanye antre: {$r['queued']}, dilewati: {$r['skipped']}, kampanye selesai: {$r['finished']}".($r['limited'] ? ' (batas harian tercapai, sisanya besok)' : ''));

        return self::SUCCESS;
    }
}
