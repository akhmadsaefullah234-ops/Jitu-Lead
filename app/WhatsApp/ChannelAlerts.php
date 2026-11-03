<?php

namespace App\WhatsApp;

use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\WaChannels\WaChannelResource;
use App\Mail\WhatsAppConnectionAlert;
use App\Mail\WhatsAppNeedsRelink;
use App\Models\Tenant;
use App\Models\WaChannel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells a tenant's admins (email and the bell in the app) when a QR gateway number drops, once per outage, and once
 * when it is back. A login that needs a new scan (broken keys, linked device removed on the phone) is told at once
 * by the webhook; any other outage is found by `whatsapp:watch` (every 5 minutes) after 5 minutes down.
 * `down_alerted_at` on the number is the last "down" alert: set, the outage was told and its recovery is still owed.
 */
class ChannelAlerts
{
    public const AFTER_MINUTES = 5;

    /** The gateway says the login needs a new scan. Called once per incident (not on repeats of the same status). */
    public function relink(WaChannel $channel, ?string $reason): void
    {
        $tenant = Tenant::query()->find($channel->tenant_id);

        if ($tenant === null) {
            return;
        }

        $removed = $reason !== null && str_contains($reason, 'dilepas');

        $this->tell($tenant, new WhatsAppNeedsRelink($tenant, $channel, $reason), Notification::make()
            ->title("WhatsApp {$channel->name} perlu scan ulang")
            ->body($removed ? 'Perangkat tertaut dilepas dari HP. Scan QR lagi agar pesan masuk ke CRM.' : 'Login WhatsApp rusak'.($reason ? " ({$reason})" : '').'. Scan QR lagi agar pesan masuk ke CRM.')
            ->danger());

        $channel->forceFill(['down_alerted_at' => now()])->save();
    }

    /**
     * Checks every QR gateway number that has ever connected: its status is taken from the gateway itself (so a
     * number marked down by a failed send heals, and one whose gateway went quiet is noticed), then a number down
     * for 5 minutes is told once and a number back after a told outage is told once.
     *
     * @return array{down: int, up: int}
     */
    public function watch(): array
    {
        $counts = ['down' => 0, 'up' => 0];

        WaChannel::query()->withoutGlobalScopes()
            ->where('type', WaChannelType::Gateway->value)
            ->whereNotNull('last_connected_at')
            // A number disconnected on purpose from the CRM is not watched until it connects again.
            ->where(fn ($q) => $q->where('status', WaChannelStatus::Connected->value)->orWhereNotNull('down_since')->orWhereNotNull('down_alerted_at'))
            ->orderBy('id')
            ->each(function (WaChannel $channel) use (&$counts) {
                try {
                    if ($outcome = $this->check($channel)) {
                        $counts[$outcome]++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $counts;
    }

    /** @return 'down'|'up'|null what was told */
    public function check(WaChannel $channel): ?string
    {
        $problem = (new GatewayProvider($channel))->checkConnection();

        if ($problem === null && $channel->status !== WaChannelStatus::Connected) {
            $channel->markConnected();
        } elseif ($problem !== null && $channel->status === WaChannelStatus::Connected) {
            $channel->markDisconnected($problem);
        }

        $tenant = Tenant::query()->find($channel->tenant_id);

        if ($tenant === null) {
            return null;
        }

        if ($channel->status === WaChannelStatus::Connected) {
            if ($channel->down_alerted_at === null) {
                return null;
            }

            $channel->forceFill(['down_alerted_at' => null])->save();
            $this->tell($tenant, new WhatsAppConnectionAlert($tenant, $channel, up: true), Notification::make()
                ->title("WhatsApp {$channel->name} tersambung lagi")
                ->body('Pesan pelanggan kembali masuk ke CRM.')
                ->success());

            return 'up';
        }

        if ($channel->down_alerted_at !== null || $channel->down_since === null || $channel->down_since->gt(now()->subMinutes(self::AFTER_MINUTES))) {
            return null;
        }

        $channel->forceFill(['down_alerted_at' => now()])->save();
        $this->tell($tenant, new WhatsAppConnectionAlert($tenant, $channel, up: false), Notification::make()
            ->title("WhatsApp {$channel->name} terputus")
            ->body('Lebih dari '.self::AFTER_MINUTES.' menit tidak tersambung. Pesan pelanggan tidak masuk ke CRM.'.(filled($channel->last_error) ? " {$channel->last_error}" : ''))
            ->danger());

        return 'down';
    }

    /** Email and an in-app notification to every active admin of the tenant. */
    private function tell(Tenant $tenant, Mailable $mail, Notification $notification): void
    {
        $admins = $tenant->activeUsers()->wherePivot('role', Role::Admin->value)->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->send(clone $mail);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $notification->title($tenant->name.': '.$notification->getTitle())
                ->actions([Action::make('open')->label('Buka Nomor WhatsApp')->url(WaChannelResource::getUrl('index', tenant: $tenant))])
                ->sendToDatabase($admins);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
