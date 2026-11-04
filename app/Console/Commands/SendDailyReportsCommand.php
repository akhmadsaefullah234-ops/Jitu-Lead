<?php

namespace App\Console\Commands;

use App\Reports\DailyReportSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:daily')]
#[Description('Kirim laporan harian, mingguan dan bulanan WhatsApp (cadangan email) yang sudah waktunya')]
class SendDailyReportsCommand extends Command
{
    public function handle(DailyReportSender $sender): int
    {
        $counts = $sender->run();

        if (array_sum($counts) > 0) {
            $this->line("Laporan: {$counts['queued']} dijadwalkan, {$counts['sent']} lewat WhatsApp, {$counts['email']} lewat email, {$counts['failed']} gagal.");
        }

        return self::SUCCESS;
    }
}
