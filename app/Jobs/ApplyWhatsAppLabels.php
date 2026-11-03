<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaLabelEvent;
use App\Support\CurrentTenant;
use App\WhatsApp\BusinessLabels;
use App\WhatsApp\GatewayProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Puts the queued WhatsApp Business labels of one lead on its chat, in order, through the QR gateway (trial,
 * App\WhatsApp\BusinessLabels). A label the account lacks or a personal account is final; the gateway being out
 * of reach is tried again (3 tries), and stops the later labels so they never go on out of order.
 */
class ApplyWhatsAppLabels implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  list<int>  $eventIds  wa_label_events, in the order the labels go on */
    public function __construct(public int $tenantId, public array $eventIds)
    {
        $this->backoff = [30, 120];
    }

    public function handle(CurrentTenant $current): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $current->run($tenant, function () {
            foreach ($this->eventIds as $id) {
                $event = WaLabelEvent::query()->with('lead')->find($id);

                if ($event === null || $event->status !== 'pending' || $event->lead === null) {
                    continue;
                }

                $channel = WaChannel::query()->find($event->wa_channel_id);

                if ($channel === null) {
                    $this->finish($event, 'error');

                    continue;
                }

                $result = (new GatewayProvider($channel))->addChatLabel((string) $event->lead->phone, $event->label);

                if ($result['ok']) {
                    $event->forceFill(['status' => 'sent', 'error' => null, 'sent_at' => now()])->save();
                    BusinessLabels::note($event->lead, $event->label, 'sent', $channel);

                    continue;
                }

                // Platform, label and reason only: never the customer's number.
                Log::warning('WhatsApp label not put on', ['tenant' => $this->tenantId, 'label' => $event->label, 'reason' => $result['code']]);

                if ($result['retry'] && $this->attempts() < $this->tries) {
                    throw new RuntimeException('WhatsApp gateway unreachable for labels.');
                }

                $this->finish($event, $result['code'] ?? 'error');
            }
        });
    }

    /** The last try failed: the labels still waiting are marked failed on the lead. */
    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        app(CurrentTenant::class)->run($tenant, function () {
            foreach (WaLabelEvent::query()->with('lead')->whereKey($this->eventIds)->where('status', 'pending')->get() as $event) {
                $this->finish($event, 'error');
            }
        });
    }

    private function finish(WaLabelEvent $event, string $code): void
    {
        $event->forceFill(['status' => 'failed', 'error' => $code])->save();

        if ($event->lead !== null) {
            BusinessLabels::note($event->lead, $event->label, $code, WaChannel::query()->find($event->wa_channel_id));
        }
    }
}
