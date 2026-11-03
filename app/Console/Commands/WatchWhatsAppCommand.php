<?php

namespace App\Console\Commands;

use App\WhatsApp\ChannelAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:watch')]
#[Description('Cek nomor WhatsApp scan QR: kabari admin agensi bila terputus lebih dari 5 menit, dan saat tersambung lagi')]
class WatchWhatsAppCommand extends Command
{
    public function handle(ChannelAlerts $alerts): int
    {
        $r = $alerts->watch();

        if ($r['down'] + $r['up'] > 0) {
            $this->line("terputus {$r['down']}, tersambung lagi {$r['up']}");
        }

        return self::SUCCESS;
    }
}
