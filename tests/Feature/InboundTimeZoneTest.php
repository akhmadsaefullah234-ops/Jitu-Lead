<?php

namespace Tests\Feature;

use App\Actions\RunFollowUps;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Incoming message times must be stored in the application's timezone (Asia/Jakarta), not UTC. */
class InboundTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->tenant->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
        config(['whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake(['gw.example.com/*' => Http::response(['id' => 'm1'])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function gateway(): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function official(): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Official, 'name' => 'Resmi', 'phone' => '+6281100000001',
            'status' => WaChannelStatus::Connected, 'position' => 0,
            'credentials' => ['phone_number_id' => '111', 'access_token' => 'tok', 'app_secret' => 'sekret', 'verify_token' => 'vt'],
        ]));
    }

    /** @param  array<string, mixed>  $payload */
    private function postGateway(WaChannel $channel, array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', "/webhooks/whatsapp/gateway/{$channel->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body);
    }

    private function postMeta(WaChannel $channel, string $from, int $epoch, bool $ad = false)
    {
        $message = ['from' => $from, 'id' => 'wamid.'.$epoch, 'timestamp' => (string) $epoch, 'type' => 'text', 'text' => ['body' => 'Info rumah']];
        if ($ad) {
            $message['referral'] = ['source_id' => 'ad1', 'source_type' => 'ad', 'headline' => 'Rumah', 'ctwa_clid' => 'c1'];
        }
        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '111'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Budi']]],
            'messages' => [$message],
        ]]]]]]);

        return $this->call('POST', "/webhooks/whatsapp/official/{$channel->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'sekret'),
        ]), $body);
    }

    /** 09:30 WIB on 12 Oct 2026 is 02:30 UTC. */
    private function epoch(string $jakarta): int
    {
        return Carbon::parse($jakarta, 'Asia/Jakarta')->getTimestamp();
    }

    public function test_helper_returns_the_application_timezone(): void
    {
        $at = InboundMessage::at($this->epoch('2026-10-12 09:30:00'));

        $this->assertSame('Asia/Jakarta', $at->timezoneName);
        $this->assertSame('2026-10-12 09:30:00', $at->format('Y-m-d H:i:s'));
        $this->assertSame(time(), InboundMessage::at(null)->getTimestamp());
    }

    public function test_gateway_inbound_is_stored_with_the_jakarta_wall_clock(): void
    {
        $gw = $this->gateway();
        $this->postGateway($gw, ['event' => 'message', 'id' => 'g1', 'from' => '081299990000', 'type' => 'text', 'text' => 'Halo', 'timestamp' => $this->epoch('2026-10-12 09:30:00')])->assertOk();

        $this->assertSame('2026-10-12 09:30:00', DB::table('wa_messages')->value('sent_at'));
        $this->assertSame('2026-10-12 09:30:00', DB::table('wa_conversations')->value('last_inbound_at'));
        $this->assertSame('2026-10-12 09:30:00', DB::table('wa_conversations')->value('last_message_at'));
    }

    public function test_official_inbound_and_ad_entry_are_stored_with_the_jakarta_wall_clock(): void
    {
        $channel = $this->official();
        $this->postMeta($channel, '6281234567890', $this->epoch('2026-10-12 09:45:10'), ad: true)->assertOk();

        $this->assertSame('2026-10-12 09:45:10', DB::table('wa_messages')->value('sent_at'));
        $this->assertSame('2026-10-12 09:45:10', DB::table('wa_conversations')->value('ad_entry_at'));
        $this->assertSame('2026-10-12 09:45:10', DB::table('wa_conversations')->value('last_inbound_at'));
    }

    public function test_inbox_order_is_chronological_across_incoming_and_outgoing(): void
    {
        $gw = $this->gateway();
        $lead = $this->makeLead($this->tenant, ['phone' => '+6281299990000']);
        $conversation = $this->inTenant(fn () => WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone]));
        $this->inTenant(fn () => $conversation->messages()->create(['channel_id' => $gw->getKey(), 'direction' => 'out', 'type' => 'text', 'body' => 'Selamat pagi', 'status' => MessageStatus::Sent, 'external_id' => 'o1', 'sent_at' => now()->subMinutes(10)]));

        $this->postGateway($gw, ['event' => 'message', 'id' => 'g2', 'from' => '081299990000', 'type' => 'text', 'text' => 'Balasan', 'timestamp' => $this->epoch('2026-10-12 09:59:00')])->assertOk();

        $bodies = $this->inTenant(fn () => $conversation->fresh()->messages->pluck('body')->all());
        $this->assertSame(['Selamat pagi', 'Balasan'], $bodies);
    }

    public function test_service_window_counts_from_the_real_time_of_the_last_inbound(): void
    {
        $gw = $this->gateway();
        $this->postGateway($gw, ['event' => 'message', 'id' => 'g3', 'from' => '081299990000', 'type' => 'text', 'text' => 'Halo', 'timestamp' => $this->epoch('2026-10-12 09:30:00')])->assertOk();
        $conversation = $this->inTenant(fn () => WaConversation::firstOrFail());

        $this->assertSame('2026-10-13 09:30:00', $conversation->serviceWindowEndsAt()->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-10-13 05:00:00', 'Asia/Jakarta'));
        $this->assertTrue($conversation->serviceWindowOpen(), 'Masih 4,5 jam lagi sebelum 24 jam berakhir');
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:31:00', 'Asia/Jakarta'));
        $this->assertFalse($conversation->serviceWindowOpen());
    }

    public function test_follow_up_sees_a_reply_that_came_after_entering_the_stage(): void
    {
        $gw = $this->gateway();
        $this->inTenant(fn () => FollowUpRule::create([
            'name' => 'H+0', 'stage_id' => $this->stage($this->tenant, 'Lead baru')->getKey(),
            'delay_days' => 0, 'send_time' => '09:00', 'body' => 'Halo {nama}',
        ]));
        $lead = $this->makeLead($this->tenant, ['phone' => '+6281299990000', 'name' => 'Budi']);
        $lead->forceFill(['stage_entered_at' => Carbon::parse('2026-10-12 08:00', 'Asia/Jakarta')])->save();

        // The client replied at 09:30 WIB, after the lead entered the stage at 08:00.
        $this->postGateway($gw, ['event' => 'message', 'id' => 'g4', 'from' => '081299990000', 'type' => 'text', 'text' => 'Halo', 'timestamp' => $this->epoch('2026-10-12 09:30:00')])->assertOk();

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 1], app(RunFollowUps::class)($this->tenant));
        $this->assertSame('Lead sudah membalas', FollowUpLog::withoutGlobalScopes()->first()->note);
    }

    public function test_migration_repairs_utc_times_once_and_leaves_correct_rows_alone(): void
    {
        $gw = $this->gateway();
        $lead = $this->makeLead($this->tenant, ['phone' => '+6281299990000']);
        $good = $this->makeLead($this->tenant, ['phone' => '+6281299990001']);

        [$wrongConv, $goodConv] = $this->inTenant(fn () => [
            WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone]),
            WaConversation::create(['lead_id' => $good->getKey(), 'phone' => $good->phone]),
        ]);

        $row = fn (WaConversation $c, string $dir, string $sent, string $created, string $ext) => DB::table('wa_messages')->insert([
            'tenant_id' => $this->tenant->getKey(), 'conversation_id' => $c->getKey(), 'channel_id' => $gw->getKey(), 'direction' => $dir,
            'type' => 'text', 'body' => $ext, 'status' => 'delivered', 'external_id' => $ext, 'sent_at' => $sent, 'created_at' => $created, 'updated_at' => $created,
        ]);

        // Stored by the bug: sent_at is the UTC wall clock, created_at the Jakarta one.
        $row($wrongConv, 'in', '2026-10-12 02:14:35', '2026-10-12 09:14:36', 'w1');
        $row($wrongConv, 'out', '2026-10-12 09:20:00', '2026-10-12 09:20:00', 'w2');
        $row($wrongConv, 'in', '2026-10-12 02:25:00', '2026-10-12 09:25:03', 'w3'); // last inbound
        // Already right: must not move.
        $row($goodConv, 'in', '2026-10-12 09:00:00', '2026-10-12 09:00:02', 'g1');

        DB::table('wa_conversations')->where('id', $wrongConv->getKey())->update(['last_inbound_at' => '2026-10-12 02:25:00', 'last_message_at' => '2026-10-12 09:20:00', 'ad_entry_at' => '2026-10-12 02:14:35']);
        DB::table('wa_conversations')->where('id', $goodConv->getKey())->update(['last_inbound_at' => '2026-10-12 09:00:00', 'last_message_at' => '2026-10-12 09:00:00']);

        $migration = require base_path('database/migrations/2026_10_23_000100_fix_inbound_whatsapp_times.php');
        $migration->up();

        $sent = fn (string $ext) => DB::table('wa_messages')->where('external_id', $ext)->value('sent_at');
        $this->assertSame('2026-10-12 09:14:35', $sent('w1'));
        $this->assertSame('2026-10-12 09:20:00', $sent('w2'));
        $this->assertSame('2026-10-12 09:25:00', $sent('w3'));
        $this->assertSame('2026-10-12 09:00:00', $sent('g1'));

        $conv = DB::table('wa_conversations')->where('id', $wrongConv->getKey())->first();
        $this->assertSame('2026-10-12 09:25:00', $conv->last_inbound_at);
        $this->assertSame('2026-10-12 09:25:00', $conv->last_message_at);
        $this->assertSame('2026-10-12 09:14:35', $conv->ad_entry_at);

        $untouched = DB::table('wa_conversations')->where('id', $goodConv->getKey())->first();
        $this->assertSame('2026-10-12 09:00:00', $untouched->last_inbound_at);

        $migration->up(); // safe to run again
        $this->assertSame('2026-10-12 09:14:35', $sent('w1'));
        $this->assertSame('2026-10-12 09:25:00', $sent('w3'));
        $this->assertSame(4, WaMessage::withoutGlobalScopes()->count());
    }
}
