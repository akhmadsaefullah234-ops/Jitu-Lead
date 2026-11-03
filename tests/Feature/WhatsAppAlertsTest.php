<?php

namespace Tests\Feature;

use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Mail\WhatsAppConnectionAlert;
use App\Mail\WhatsAppNeedsRelink;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A QR gateway number that drops: its tenant's admins get one email and one bell notification per outage (at once
 * when the linked device was removed on the phone, after 5 minutes otherwise), and one when it is back.
 */
class WhatsAppAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private WaChannel $gw;

    /** What the gateway says about the session (GET /session). */
    private string $gatewayStatus = 'connected';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = $this->makeTenant('griya-alert');
        $this->admin = $this->adminOf($this->tenant);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake(['gw.example.com/session' => fn () => Http::response(['status' => $this->gatewayStatus])]);
        $this->gw = app(CurrentTenant::class)->run($this->tenant, fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Admin Toko', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 'gwsecret'],
        ]));
        $this->gw->markConnected();
        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function webhook(array $payload): void
    {
        $body = json_encode($payload);

        $this->call('POST', "/webhooks/whatsapp/gateway/{$this->gw->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 'gwsecret'),
        ]), $body)->assertOk();
    }

    private function watch(): void
    {
        $this->artisan('whatsapp:watch')->assertSuccessful();
    }

    private function channel(): WaChannel
    {
        return WaChannel::query()->withoutGlobalScopes()->find($this->gw->getKey());
    }

    /** @return list<string> the bell notifications' titles, oldest first */
    private function bell(): array
    {
        return $this->admin->notifications()->reorder()->oldest()->get()->map(fn ($n) => $n->data['title'])->all();
    }

    public function test_a_linked_device_removed_on_the_phone_mails_once_with_the_reason_then_once_when_back(): void
    {
        $this->webhook(['event' => 'session', 'status' => 'needs_relink', 'reason' => 'perangkat tertaut dilepas dari HP']);
        $this->webhook(['event' => 'session', 'status' => 'needs_relink', 'reason' => 'perangkat tertaut dilepas dari HP']);

        $this->assertSame(WaChannelStatus::NeedsRelink, $this->channel()->status);
        $this->assertSame('Perlu scan QR ulang: perangkat tertaut dilepas dari HP.', $this->channel()->last_error);
        Mail::assertSent(WhatsAppNeedsRelink::class, 1);
        Mail::assertSent(WhatsAppNeedsRelink::class, fn ($m) => $m->hasTo($this->admin->email) && str_contains($m->render(), 'perangkat tertaut dilepas dari HP'));
        $this->assertSame(['Griya Alert: WhatsApp Admin Toko perlu scan ulang'], $this->bell());

        // Still down 10 minutes later: the watcher does not mail the same outage again.
        $this->gatewayStatus = 'needs_relink';
        $this->travel(10)->minutes();
        $this->watch();
        Mail::assertNotSent(WhatsAppConnectionAlert::class);
        $this->assertSame(WaChannelStatus::NeedsRelink, $this->channel()->status, 'the watcher never hides that a scan is needed');

        // Scanned again: one "back" email, once.
        $this->gatewayStatus = 'connected';
        $this->webhook(['event' => 'session', 'status' => 'connected']);
        $this->watch();
        $this->watch();
        Mail::assertSent(WhatsAppConnectionAlert::class, 1);
        Mail::assertSent(WhatsAppConnectionAlert::class, fn ($m) => $m->up && str_contains($m->envelope()->subject, 'tersambung lagi'));
        Mail::assertSent(WhatsAppNeedsRelink::class, 1);
        $this->assertSame(['Griya Alert: WhatsApp Admin Toko perlu scan ulang', 'Griya Alert: WhatsApp Admin Toko tersambung lagi'], $this->bell());
    }

    public function test_a_number_down_for_5_minutes_is_mailed_once_and_once_when_back(): void
    {
        $this->gatewayStatus = 'qr_required';
        $this->watch();
        $this->assertSame(WaChannelStatus::Disconnected, $this->channel()->status, 'the gateway says it dropped, without a webhook');
        Mail::assertNothingSent();

        $this->travel(4)->minutes();
        $this->watch();
        Mail::assertNothingSent();

        $this->travel(2)->minutes();
        $this->watch();
        $this->watch();
        Mail::assertSent(WhatsAppConnectionAlert::class, 1);
        Mail::assertSent(WhatsAppConnectionAlert::class, fn ($m) => ! $m->up && $m->hasTo($this->admin->email) && str_contains($m->render(), 'lebih dari 5 menit'));
        $this->assertNotNull($this->channel()->down_alerted_at);

        $this->travel(30)->minutes();
        $this->watch();
        Mail::assertSent(WhatsAppConnectionAlert::class, 1);

        $this->gatewayStatus = 'connected';
        $this->watch();
        $this->watch();
        $this->assertSame(WaChannelStatus::Connected, $this->channel()->status);
        Mail::assertSent(WhatsAppConnectionAlert::class, 2);
        Mail::assertSent(WhatsAppConnectionAlert::class, fn ($m) => $m->up);
        $this->assertNull($this->channel()->down_alerted_at);
        $this->assertSame(['Griya Alert: WhatsApp Admin Toko terputus', 'Griya Alert: WhatsApp Admin Toko tersambung lagi'], $this->bell());
    }

    public function test_a_short_drop_a_failed_send_or_a_deliberate_disconnect_mails_nobody(): void
    {
        // Dropped and back within 5 minutes.
        $this->gatewayStatus = 'qr_required';
        $this->watch();
        $this->travel(3)->minutes();
        $this->gatewayStatus = 'connected';
        $this->watch();
        $this->travel(10)->minutes();
        $this->watch();

        // A send that failed while the gateway was still connected heals on the next check.
        $this->channel()->markFailed('Tidak bisa menghubungi gateway: timeout');
        $this->travel(10)->minutes();
        $this->watch();
        $this->assertSame(WaChannelStatus::Connected, $this->channel()->status);

        // Disconnected on purpose from the CRM.
        $this->channel()->markDisconnected('Diputuskan dari CRM.', deliberate: true);
        $this->gatewayStatus = 'disconnected';
        $this->travel(10)->minutes();
        $this->watch();
        $this->assertSame(WaChannelStatus::Disconnected, $this->channel()->status);

        Mail::assertNothingSent();
        $this->assertSame([], $this->bell());
    }

    public function test_the_needs_relink_email_without_a_reason_keeps_the_general_text(): void
    {
        $this->webhook(['event' => 'session', 'status' => 'needs_relink']);

        Mail::assertSent(WhatsAppNeedsRelink::class, fn ($m) => str_contains($m->render(), 'Login WhatsApp-nya rusak') && ! str_contains($m->render(), 'Penyebab'));
    }
}
