<?php

namespace App\Console\Commands;

use App\Retention\TrialEmails;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trial-emails:send')]
#[Description('Kirim email berseri masa percobaan yang jatuh hari ini (sekali per agensi, dalam batas harian)')]
class TrialEmailsCommand extends Command
{
    public function handle(TrialEmails $emails): int
    {
        $r = $emails->run();
        $this->info("Email antre: {$r['sent']} ke {$r['agencies']} agensi, dilewati: {$r['skipped']}".($r['limited'] ? ' (batas harian tercapai, sisanya besok)' : ''));

        return self::SUCCESS;
    }
}
