<?php

namespace App\Filament\Pages;

use App\Models\PushSubscription;
use App\Push\PushTransport;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;

/**
 * Per person: which devices get push notifications, and for what. Everyone can open it (agents too),
 * because each person decides for their own phone and computer.
 */
class Notifications extends Page
{
    protected string $view = 'filament.pages.notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Notifikasi';

    protected static ?string $title = 'Notifikasi di HP dan komputer';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 1;

    protected const MAX_DEVICES = 10;

    public bool $notifyLeads = true;

    public bool $notifyMessages = true;

    public function mount(): void
    {
        $this->notifyLeads = (bool) auth()->user()->notify_leads;
        $this->notifyMessages = (bool) auth()->user()->notify_messages;
    }

    public function updatedNotifyLeads(): void
    {
        auth()->user()->forceFill(['notify_leads' => $this->notifyLeads])->save();
    }

    public function updatedNotifyMessages(): void
    {
        auth()->user()->forceFill(['notify_messages' => $this->notifyMessages])->save();
    }

    public function transportReady(): bool
    {
        return app(PushTransport::class)->enabled();
    }

    public function publicKey(): ?string
    {
        return config('jitu.push.public_key') ?: null;
    }

    /** @return list<array{id: int, name: string, endpoint: string, since: string}> */
    public function devices(): array
    {
        return auth()->user()->pushSubscriptions()->latest('id')->get()
            ->map(fn (PushSubscription $s) => ['id' => $s->getKey(), 'name' => $s->device ?: 'Perangkat', 'endpoint' => $s->endpoint, 'since' => $s->created_at->translatedFormat('j M Y')])
            ->all();
    }

    /**
     * Called by the page after the browser created a push subscription.
     *
     * @param  array{endpoint?: mixed, keys?: array{p256dh?: mixed, auth?: mixed}}  $subscription
     */
    public function saveSubscription(array $subscription, ?string $userAgent = null): bool
    {
        $v = Validator::make($subscription, [
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        if ($v->fails() || ! PushTransport::allowedEndpoint($subscription['endpoint'])) {
            return false;
        }

        $user = auth()->user();

        // The same browser can be used by different people; a device belongs to whoever enabled it last.
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => PushSubscription::hash($subscription['endpoint'])], [
            'user_id' => $user->getKey(),
            'endpoint' => $subscription['endpoint'],
            'p256dh' => $subscription['keys']['p256dh'],
            'auth' => $subscription['keys']['auth'],
            'device' => PushSubscription::deviceName($userAgent),
        ]);

        $extra = $user->pushSubscriptions()->latest('id')->skip(self::MAX_DEVICES)->pluck('id');
        PushSubscription::query()->whereIn('id', $extra)->delete();

        return true;
    }

    public function removeSubscription(string $endpoint): void
    {
        auth()->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hash($endpoint))->delete();
    }

    public function removeDevice(int $id): void
    {
        auth()->user()->pushSubscriptions()->whereKey($id)->delete();
    }

    public function sendTest(): void
    {
        $subs = auth()->user()->pushSubscriptions()->get();

        if (! $this->transportReady() || $subs->isEmpty()) {
            Notification::make()->title('Belum ada perangkat yang aktif')->warning()->send();

            return;
        }

        $results = app(PushTransport::class)->send($subs, [
            'title' => 'Tes notifikasi JITU LEAD',
            'body' => 'Jika Anda melihat ini, notifikasi di perangkat Anda berfungsi.',
            'url' => static::getUrl(),
            'tag' => 'tes',
        ]);

        PushSubscription::query()->whereIn('id', array_keys(array_filter($results, fn ($r) => $r === 'gone')))->delete();
        $ok = count(array_filter($results, fn ($r) => $r === 'ok'));

        $ok > 0
            ? Notification::make()->title("Terkirim ke {$ok} perangkat")->success()->send()
            : Notification::make()->title('Gagal terkirim')->body('Aktifkan ulang notifikasi di perangkat ini, lalu coba lagi.')->danger()->send();
    }
}
