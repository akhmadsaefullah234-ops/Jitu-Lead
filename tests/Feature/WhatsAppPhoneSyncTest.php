<?php

namespace Tests\Feature;

use App\Enums\AiMode;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Jobs\GenerateAiReplyJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Push\Alerts;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Replies an agent types in the WhatsApp app on the gateway phone must show up in the CRM as outgoing messages. */
class WhatsAppPhoneSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    private WaChannel $gw;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->tenant = $this->makeTenant();
        $this->gw = $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    /** @param  array<string, mixed>  $payload */
    private function webhook(array $payload): void
    {
        $body = json_encode($payload + ['event' => 'message', 'from' => '081299990000', 'type' => 'text', 'timestamp' => time(), 'name' => null]);

        $this->call('POST', "/webhooks/whatsapp/gateway/{$this->gw->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body)->assertOk();
    }

    private function lead(): Lead
    {
        $lead = $this->makeLead($this->tenant, ['phone' => '081299990000']);
        $this->inTenant(fn () => $lead->forceFill(['assigned_at' => now()->subHours(2)])->saveQuietly());

        return $lead;
    }

    public function test_a_reply_from_the_phone_is_stored_as_an_outgoing_message_without_customer_side_effects(): void
    {
        $lead = $this->lead();
        $this->mock(Alerts::class)->shouldNotReceive('newLead', 'newMessage');
        $inbound = now()->subHour();
        $this->inTenant(fn () => WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone, 'last_inbound_at' => $inbound, 'unread_count' => 2]));

        $this->webhook(['id' => 'hp1', 'text' => 'Siap kak, saya kirim brosurnya', 'from_me' => true]);

        $this->inTenant(function () use ($lead, $inbound) {
            $message = WaMessage::query()->where('external_id', 'hp1')->firstOrFail();
            $this->assertSame('out', $message->direction);
            $this->assertTrue($message->from_phone);
            $this->assertSame(MessageStatus::Sent, $message->status);
            $this->assertSame('Siap kak, saya kirim brosurnya', $message->body);

            $conversation = $message->conversation;
            $this->assertSame($lead->getKey(), $conversation->lead_id);
            $this->assertSame(0, $conversation->unread_count);
            $this->assertSame($inbound->timestamp, $conversation->last_inbound_at->timestamp, 'Follow-ups must not treat it as a customer reply');
            $this->assertTrue($conversation->ai_paused_until->isFuture(), 'The AI steps back while the agent replies by hand');

            $this->assertTrue($lead->activities()->where('type', 'whatsapp_out_phone')->exists());
            $this->assertFalse($lead->activities()->where('type', 'whatsapp_in')->exists());
            $this->assertFalse(Lead::query()->unattended(30)->whereKey($lead->getKey())->exists(), 'The lead counts as attended to');
        });

        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_an_unanswered_lead_still_counts_as_unattended_before_the_phone_reply(): void
    {
        $lead = $this->lead();

        $this->assertTrue($this->inTenant(fn () => Lead::query()->unattended(30)->whereKey($lead->getKey())->exists()));
    }

    public function test_the_echo_of_a_message_sent_from_the_crm_is_not_stored_twice(): void
    {
        $lead = $this->lead();
        $this->inTenant(function () use ($lead) {
            $conversation = WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone]);
            $conversation->messages()->create(['channel_id' => $this->gw->getKey(), 'direction' => 'out', 'type' => 'text', 'body' => 'Dari CRM', 'status' => MessageStatus::Sent, 'external_id' => 'crm1', 'sent_at' => now()]);
        });

        $this->webhook(['id' => 'crm1', 'text' => 'Dari CRM', 'from_me' => true]);
        $this->webhook(['id' => 'hp2', 'text' => 'Dari HP', 'from_me' => true]);
        $this->webhook(['id' => 'hp2', 'text' => 'Dari HP', 'from_me' => true]);

        $this->inTenant(function () {
            $this->assertSame(2, WaMessage::query()->count());
            $this->assertFalse(WaMessage::query()->where('external_id', 'crm1')->value('from_phone'));
        });
    }

    public function test_a_phone_message_to_an_unknown_number_creates_no_lead_unless_configured(): void
    {
        $this->webhook(['id' => 'hp3', 'text' => 'Halo', 'from_me' => true]);

        $this->inTenant(function () {
            $this->assertSame(0, Lead::query()->count());
            $this->assertSame(0, WaMessage::query()->count());
        });

        config(['whatsapp.phone_messages_create_leads' => true]);
        $this->webhook(['id' => 'hp4', 'text' => 'Halo', 'from_me' => true]);

        $this->inTenant(function () {
            $lead = Lead::query()->sole();
            $this->assertSame('+6281299990000', $lead->phone);
            $this->assertSame('out', WaMessage::query()->sole()->direction);
        });
        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_a_picture_sent_from_the_phone_is_kept_like_an_incoming_one(): void
    {
        $this->lead();

        $this->webhook(['id' => 'hp5', 'type' => 'image', 'text' => 'ini denahnya', 'from_me' => true, 'media' => ['mime_type' => 'image/png', 'data' => self::PNG]]);

        $message = $this->inTenant(fn () => WaMessage::query()->sole());
        $this->assertSame('out', $message->direction);
        $this->assertSame('ini denahnya', $message->body);
        $this->assertArrayNotHasKey('data', $message->media);
        Storage::disk('local')->assertExists($message->media['path']);
    }

    public function test_a_customer_message_still_triggers_the_ai(): void
    {
        $this->lead();

        $this->webhook(['id' => 'in1', 'text' => 'Masih ada unitnya?']);

        Queue::assertPushed(GenerateAiReplyJob::class);
        $this->assertSame('in', $this->inTenant(fn () => WaMessage::query()->sole()->direction));
    }
}
