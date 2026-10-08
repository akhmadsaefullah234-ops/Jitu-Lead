<?php

namespace Tests\Feature;

use App\Actions\SendWhatsAppMessage;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\RouteKind;
use App\WhatsApp\RouteSelector;
use App\WhatsApp\WhatsAppException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        app(CurrentTenant::class)->set($this->tenant);
        config(['whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        $this->member($this->tenant, Role::Agent);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function official(array $overrides = []): WaChannel
    {
        return WaChannel::create($overrides + [
            'type' => WaChannelType::Official, 'name' => 'Resmi', 'phone' => '+6281100000001',
            'status' => WaChannelStatus::Connected, 'position' => 0,
            'credentials' => ['phone_number_id' => '111', 'access_token' => 'tok', 'app_secret' => 'sekret', 'verify_token' => 'vt'],
        ]);
    }

    private function gateway(string $name = 'Gateway 1', int $position = 1): WaChannel
    {
        return WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => $name, 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => $position,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 'gwsecret'],
        ]);
    }

    private function conversation(array $attrs = []): WaConversation
    {
        $lead = $this->makeLead($this->tenant);

        return WaConversation::create($attrs + ['lead_id' => $lead->getKey(), 'phone' => $lead->phone]);
    }

    private function send(WaConversation $c, ?string $text = 'Halo', bool $confirm = false, ?WaTemplate $tpl = null): WaMessage
    {
        return app(SendWhatsAppMessage::class)($c, $this->adminOf($this->tenant), $text, $confirm, $tpl);
    }

    private function template(string $status = 'approved'): WaTemplate
    {
        return WaTemplate::firstOrCreate(['name' => 'follow_up_'.$status, 'language' => 'id'], ['category' => 'marketing', 'body' => 'Halo {nama}', 'status' => $status]);
    }

    public function test_free_window_routes_to_official(): void
    {
        $this->official();
        $this->gateway();
        $c = $this->conversation(['free_window_ends_at' => now()->addHours(10)]);

        $this->assertSame(RouteKind::FreeWindow, app(RouteSelector::class)->decide($c)->kind);
    }

    public function test_recent_client_message_replies_from_same_number(): void
    {
        $this->official();
        $gw = $this->gateway();
        $c = $this->conversation(['last_inbound_at' => now()->subHours(2), 'last_inbound_channel_id' => $gw->getKey()]);

        $d = app(RouteSelector::class)->decide($c);
        $this->assertSame(RouteKind::ReplyGateway, $d->kind);
        $this->assertTrue($d->channel->is($gw));
    }

    public function test_closed_windows_use_gateway_with_intro_first(): void
    {
        $this->official();
        $this->gateway();
        $c = $this->conversation(['last_inbound_at' => now()->subDays(3)]);

        $d = app(RouteSelector::class)->decide($c);
        $this->assertSame(RouteKind::FollowUpGateway, $d->kind);
        $this->assertTrue($d->needsIntro);

        Http::fake(['gw.example.com/*' => Http::sequence()->push(['id' => 'a1'])->push(['id' => 'a2'])]);
        $this->send($c, 'Ada unit baru');

        $messages = $c->messages()->get();
        $this->assertCount(2, $messages);
        $this->assertTrue($messages[0]->is_intro);
        $this->assertSame('Ada unit baru', $messages[1]->body);
        $this->assertFalse(app(RouteSelector::class)->decide($c->fresh())->needsIntro);
    }

    public function test_without_gateway_only_a_confirmed_paid_template_goes_out(): void
    {
        $this->official();
        $c = $this->conversation();

        $this->assertSame(RouteKind::PaidTemplate, app(RouteSelector::class)->decide($c)->kind);

        try {
            $this->send($c, null, false, $this->template());
            $this->fail('Unconfirmed paid send went out.');
        } catch (WhatsAppException) {
        }

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]])]);
        $message = $this->send($c, null, true, $this->template());
        $this->assertTrue($message->is_paid);

        $this->expectException(WhatsAppException::class);
        $this->send($c, null, true, $this->template('pending'));
    }

    public function test_nothing_connected_is_unavailable(): void
    {
        $c = $this->conversation();
        $this->assertSame(RouteKind::Unavailable, app(RouteSelector::class)->decide($c)->kind);
        $this->expectException(WhatsAppException::class);
        $this->send($c);
    }

    public function test_backup_gateway_used_when_first_fails(): void
    {
        $first = $this->gateway('Utama', 1);
        $second = $this->gateway('Cadangan', 2);
        $c = $this->conversation();
        $c->messages()->create(['channel_id' => $first->getKey(), 'direction' => 'out', 'type' => 'text', 'body' => 'x', 'status' => MessageStatus::Sent, 'sent_at' => now()->subDays(5)]);
        $c->messages()->create(['channel_id' => $second->getKey(), 'direction' => 'out', 'type' => 'text', 'body' => 'x', 'status' => MessageStatus::Sent, 'sent_at' => now()->subDays(5)]);

        Http::fake(['gw.example.com/*' => Http::sequence()->push(['error' => 'down'], 503)->push(['id' => 'ok1'])]);
        $message = $this->send($c, 'Halo lagi');

        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertTrue($message->channel->is($second));
        $this->assertSame(WaChannelStatus::Error, $first->fresh()->status);
    }

    public function test_replying_to_an_ad_within_24h_opens_free_window(): void
    {
        $official = $this->official();
        $c = $this->conversation(['ad_entry_at' => now()->subHours(3), 'last_inbound_at' => now()->subHours(3), 'last_inbound_channel_id' => $official->getKey()]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w2']]])]);
        $this->send($c, 'Selamat siang');

        $this->assertTrue($c->fresh()->freeWindowOpen());
    }

    private function metaPayload(string $id = 'wamid.1', bool $ad = true): array
    {
        $message = ['from' => '6281234567890', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Info rumah']];
        if ($ad) {
            $message['referral'] = ['source_id' => 'ad123', 'source_type' => 'ad', 'headline' => 'Rumah Cluster', 'ctwa_clid' => 'clid1'];
        }

        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '111'],
            'contacts' => [['wa_id' => '6281234567890', 'profile' => ['name' => 'Budi']]],
            'messages' => [$message],
        ]]]]]];
    }

    private function postMeta(WaChannel $channel, array $payload, ?string $secret = 'sekret')
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? 'salah')];

        return $this->call('POST', "/webhooks/whatsapp/official/{$channel->webhook_token}", [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    public function test_official_webhook_creates_lead_with_ad_data_and_is_idempotent(): void
    {
        $channel = $this->official();
        app(CurrentTenant::class)->set(null);

        $this->postMeta($channel, $this->metaPayload())->assertOk();
        $this->postMeta($channel, $this->metaPayload())->assertOk(); // retry

        app(CurrentTenant::class)->set($this->tenant);
        $lead = Lead::where('phone', '+6281234567890')->firstOrFail();
        $this->assertSame('Budi', $lead->name);
        $this->assertSame(1, Lead::where('phone', '+6281234567890')->count());
        $this->assertSame(1, WaMessage::count());

        $conversation = WaConversation::firstOrFail();
        $this->assertNotNull($conversation->ad_entry_at);
        $this->assertSame('ad123', $conversation->ad_data['source_id']);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('Iklan Meta', $lead->source->name);
        $this->assertNotNull($lead->owner_id);
    }

    public function test_webhook_rejects_bad_signature_and_unknown_token(): void
    {
        $channel = $this->official();
        app(CurrentTenant::class)->set(null);

        $this->postMeta($channel, $this->metaPayload(), 'salah')->assertUnauthorized();
        $this->call('POST', '/webhooks/whatsapp/official/tokensalah', [], [], [], [], '{}')->assertNotFound();
        $this->post("/webhooks/whatsapp/official/{$channel->webhook_token}", [])->assertUnauthorized();

        app(CurrentTenant::class)->set($this->tenant);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_official_channel_token_does_not_work_on_gateway_route(): void
    {
        $channel = $this->official();
        $this->postJson("/webhooks/whatsapp/gateway/{$channel->webhook_token}", [])->assertNotFound();
    }

    public function test_meta_verification_handshake(): void
    {
        $channel = $this->official();
        $url = "/webhooks/whatsapp/official/{$channel->webhook_token}";

        $this->get("$url?hub.mode=subscribe&hub.verify_token=vt&hub.challenge=12345")->assertOk()->assertSee('12345');
        $this->get("$url?hub.mode=subscribe&hub.verify_token=salah&hub.challenge=12345")->assertForbidden();
    }

    public function test_gateway_webhook_message_and_status(): void
    {
        $gw = $this->gateway();
        $out = $this->conversation();
        $out->messages()->create(['channel_id' => $gw->getKey(), 'direction' => 'out', 'type' => 'text', 'body' => 'x', 'status' => MessageStatus::Sent, 'external_id' => 'g9', 'sent_at' => now()]);
        app(CurrentTenant::class)->set(null);

        $post = function (array $payload, string $secret = 'gwsecret') use ($gw) {
            $body = json_encode($payload);

            return $this->call('POST', "/webhooks/whatsapp/gateway/{$gw->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/json',
                'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ]), $body);
        };

        $post(['event' => 'message', 'id' => 'm1', 'from' => '081299990000', 'type' => 'text', 'text' => 'Halo', 'timestamp' => time(), 'name' => 'Sari'])->assertOk();
        $post(['event' => 'status', 'id' => 'g9', 'status' => 'read'])->assertOk();
        $post(['event' => 'status', 'id' => 'g9', 'status' => 'sent'])->assertOk(); // must not downgrade
        $post(['event' => 'message', 'id' => 'm2', 'from' => '081299990001', 'type' => 'text', 'text' => 'Palsu'], 'salah')->assertUnauthorized();

        app(CurrentTenant::class)->set($this->tenant);
        $this->assertSame(MessageStatus::Read, WaMessage::where('external_id', 'g9')->first()->status);
        $this->assertTrue(Lead::where('phone', '+6281299990000')->exists());
        $this->assertFalse(Lead::where('phone', '+6281299990001')->exists());
        $this->assertSame($gw->getKey(), WaConversation::whereHas('lead', fn ($q) => $q->where('phone', '+6281299990000'))->first()->last_inbound_channel_id);
    }

    public function test_channels_and_conversations_are_tenant_isolated(): void
    {
        $this->official();
        $c = $this->conversation();
        $other = $this->makeTenant('nusa-properti');

        app(CurrentTenant::class)->set($other);
        $this->assertSame(0, WaChannel::count());
        $this->assertSame(0, WaConversation::count());
        $this->assertSame(RouteKind::Unavailable, app(RouteSelector::class)->decide($c)->kind);
    }

    public function test_credentials_are_encrypted_at_rest_and_hidden(): void
    {
        $channel = $this->official();
        $raw = \DB::table('wa_channels')->where('id', $channel->getKey())->value('credentials');

        $this->assertStringNotContainsString('sekret', $raw);
        $this->assertArrayNotHasKey('credentials', $channel->toArray());
    }

    public function test_gateway_url_guard(): void
    {
        $this->assertNull(GatewayUrlGuard::problem('https://gw.example.com'));
        $this->assertNotNull(GatewayUrlGuard::problem('http://gw.example.com'));
        $this->assertNotNull(GatewayUrlGuard::problem('https://127.0.0.1'));
        $this->assertNotNull(GatewayUrlGuard::problem('https://169.254.169.254/latest'));
        $this->assertNotNull(GatewayUrlGuard::problem('https://10.0.0.5'));
        $this->assertNotNull(GatewayUrlGuard::problem('https://user:pw@gw.example.com'));
        $this->assertNotNull(GatewayUrlGuard::problem('ftp://gw.example.com'));
    }
}
