<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaLabelEvent;
use App\Support\CurrentTenant;
use App\WhatsApp\BusinessLabels;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('whatsapp:labels-retry')]
#[Description('Pasang label WhatsApp Business yang menunggu labelnya ada di WhatsApp (maks 7 hari, lalu gagal)')]
class RetryWhatsAppLabelsCommand extends Command
{
    public function handle(CurrentTenant $current): int
    {
        $channels = WaLabelEvent::query()->withoutGlobalScopes()->where('status', 'waiting_label')
            ->whereNotNull('wa_channel_id')->distinct()->pluck('wa_channel_id');

        foreach ($channels as $channelId) {
            $channel = WaChannel::query()->withoutGlobalScopes()->find($channelId);
            $tenant = $channel ? Tenant::query()->find($channel->tenant_id) : null;

            if ($tenant === null) {
                continue;
            }

            try {
                $r = $current->run($tenant, fn () => BusinessLabels::retryWaiting($channel));
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($r['retried'] + $r['expired'] > 0) {
                $this->line("{$tenant->slug} / {$channel->name}: dicoba lagi {$r['retried']}, gagal setelah 7 hari {$r['expired']}");
            }
        }

        // Events of a deleted number can never go on: they fail once their 7 days are over.
        WaLabelEvent::query()->withoutGlobalScopes()->where('status', 'waiting_label')->whereNull('wa_channel_id')
            ->where('waiting_since', '<=', now()->subDays(BusinessLabels::WAIT_DAYS))->update(['status' => 'failed']);

        return self::SUCCESS;
    }
}
