<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Ai\AiSettings;
use App\Ai\AnthropicClient;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Jobs\GenerateAiReplyJob;
use App\Mail\AiReplyFailed;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The morning answer after the night greeting (2026-11-16: five chats stopped at "kami balas besok pagi" because the
 * API refused a chat that ended with the agency's turn), what happens when the AI fails, ai:retry-stuck, and the
 * "Tanyakan tipe lewat daftar" setting.
 */
class AiMorningAndRetryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WaChannel $channel;

    private int $n = 0;

    /** @var list<array> responses the fake API gives, in order; then a plain answer */
    private array $api = [];

    private string $answer = 'Met pagi kak 😊 Tipe Tulip mulai 600 jutaan ya.';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Mail::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 05:30', 'Asia/Makassar'));
        $this->tenant = $this->makeTenant('griya-pelangi');
        $this->tenant->forceFill(['name' => 'Rumahkudotcom', 'timezone' => 'Asia/Makassar'])->save();
        $this->settings(['quiet_from' => '21:00', 'quiet_until' => '06:00', 'quiet_mode' => 'greet']);
        $this->channel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Nomor utama', 'phone' => '+6289900000001',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Harga', 'content' => 'Tipe Tulip mulai 600 jutaan.']));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => array_shift($this->api) ?? Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->answer]]]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n, 'ok' => true]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function settings(array $values): void
    {
        $this->tenant->forceFill(['ai_settings' => AiSettings::fromForm($values + AiSettings::of($this->tenant)->form())])->save();
    }

    private function says(string $text, string $from = '6289900001234'): WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($from), 'text', $text, null, CarbonImmutable::now(), 'Rina',
        )));
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    /** @return list<array> the messages of every request to the model */
    private function requests(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->map(fn ($pair) => $pair[0]['messages'])->values()->all();
    }

    private function refused(): PromiseInterface
    {
        return Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'This model does not support assistant message prefill. The conversation must end with a user message.']], 400);
    }

    /** A night message greeted at 05:30, its full answer due at 06:00. */
    private function night(string $text = 'Malam kak, harga Tulip berapa?'): WaMessage
    {
        Queue::fake([GenerateAiReplyJob::class]);
        $message = $this->says($text);
        $this->in(fn () => app(GenerateAiReply::class)($message));
        $this->assertSame([AiSettings::of($this->tenant)->quietMessage()], $this->sent());
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 06:02', 'Asia/Makassar'));

        return $message;
    }

    // ---- 1a. The morning chat ends with the client ------------------------------------------------------------

    public function test_the_morning_answer_sends_a_chat_that_ends_with_the_client_and_leaves_out_the_greeting(): void
    {
        $message = $this->night();

        $draft = $this->in(fn () => app(GenerateAiReply::class)($message->fresh(), true));

        $this->assertSame(AiDraft::SENT, $draft->status);
        $this->assertSame([AiSettings::of($this->tenant)->quietMessage(), $this->answer], $this->sent());
        $messages = collect($this->requests())->last();
        $this->assertSame('user', collect($messages)->last()['role']);
        $this->assertStringNotContainsString('besok pagi', json_encode($messages, JSON_UNESCAPED_UNICODE));
    }

    public function test_turns_are_normalised_for_the_api(): void
    {
        $this->assertSame([
            ['role' => 'user', 'content' => "Halo\nHarga?"],
            ['role' => 'assistant', 'content' => 'Mulai 600 jutaan'],
            ['role' => 'user', 'content' => 'Lokasi?'],
        ], AnthropicClient::normalize([
            ['role' => 'assistant', 'content' => 'Sapaan'],
            ['role' => 'user', 'content' => 'Halo'],
            ['role' => 'user', 'content' => 'Harga?'],
            ['role' => 'assistant', 'content' => 'Mulai 600 jutaan'],
            ['role' => 'user', 'content' => 'Lokasi?'],
            ['role' => 'assistant', 'content' => 'Kami balas besok pagi'],
        ]));
    }

    public function test_the_apis_reason_is_kept_without_numbers_from_the_chat(): void
    {
        $this->assertSame('invalid_request_error: messages: field [angka] is wrong', AnthropicClient::reason(['error' => ['type' => 'invalid_request_error', 'message' => "messages:  field\n 089900012345 is wrong"]]));
        $this->assertSame('', AnthropicClient::reason(null));
    }

    // ---- 1b. A failure is never silent ------------------------------------------------------------------------

    public function test_a_refused_request_is_tried_once_more_then_handed_to_an_agent_who_is_emailed(): void
    {
        $message = $this->night();
        $this->api = [$this->refused(), $this->refused()];

        $draft = $this->in(fn () => app(GenerateAiReply::class)($message->fresh(), true));

        $this->assertSame(AiDraft::HANDOFF, $draft->status);
        $this->assertCount(2, $this->requests());
        $this->assertSame([AiSettings::of($this->tenant)->quietMessage()], $this->sent(), 'Nothing invented is sent');
        $this->assertSame('AI gagal menjawab: layanan AI menolak permintaan (kode 400)',
            $this->in(fn () => LeadActivity::query()->where('type', 'ai_handoff')->value('body')));
        Mail::assertSent(AiReplyFailed::class, fn (AiReplyFailed $m) => $m->hasTo($this->tenant->users()->first()->email));

        // A second failure within the hour is on the Inbox, not in another e-mail.
        $other = $this->says('Halo kak', '6289900005678');
        $this->api = [$this->refused(), $this->refused()];
        $this->in(fn () => app(GenerateAiReply::class)($other, true));
        Mail::assertSentCount(1);
        $this->assertSame(2, $this->in(fn () => AiDraft::query()->where('status', AiDraft::HANDOFF)->count()));
    }

    public function test_a_failure_that_passes_on_the_second_try_is_answered(): void
    {
        $message = $this->night();
        $this->api = [Http::response(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)];

        $draft = $this->in(fn () => app(GenerateAiReply::class)($message->fresh(), true));

        $this->assertSame(AiDraft::SENT, $draft->status);
        $this->assertSame(0, $this->in(fn () => LeadActivity::query()->where('type', 'ai_handoff')->count()));
        Mail::assertNothingSent();
    }

    // ---- 1c. ai:retry-stuck -----------------------------------------------------------------------------------

    public function test_retry_stuck_answers_a_chat_stopped_at_the_night_greeting_once(): void
    {
        $this->night();
        $this->in(fn () => WaMessage::query()->where('direction', 'in')->update(['sent_at' => now()->subMinutes(40)]));
        $this->in(fn () => WaConversation::query()->update(['last_inbound_at' => now()->subMinutes(40)]));

        $this->artisan('ai:retry-stuck', ['--tenant' => 'griya-pelangi'])
            ->expectsOutputToContain('berhenti di sambutan jam malam')
            ->expectsOutputToContain('1 percakapan macet, 1 dijalankan ulang.')
            ->assertSuccessful();
        $this->assertSame([AiSettings::of($this->tenant)->quietMessage(), $this->answer], $this->sent());

        $this->artisan('ai:retry-stuck', ['--tenant' => 'griya-pelangi'])
            ->expectsOutputToContain('0 percakapan macet')
            ->assertSuccessful();
        $this->assertCount(2, $this->sent(), 'Never answered twice');
    }

    public function test_retry_stuck_picks_an_unanswered_message_and_leaves_recent_ones(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Makassar'));
        Queue::fake([GenerateAiReplyJob::class]);
        $old = $this->says('Halo kak, info rumah', '6289900001111');
        $this->in(fn () => $old->forceFill(['sent_at' => now()->subHour()])->save());
        $old->conversation->forceFill(['last_inbound_at' => now()->subHour()])->save();
        $this->says('Baru saja tanya', '6289900002222');

        $this->artisan('ai:retry-stuck', ['--tenant' => 'griya-pelangi', '--dry-run' => true])
            ->expectsOutputToContain('pesan klien belum dibalas')
            ->expectsOutputToContain('1 percakapan macet')
            ->assertSuccessful();
        $this->assertSame([], $this->sent(), 'A dry run sends nothing');
    }

    public function test_retry_stuck_waits_during_quiet_hours_and_needs_an_agency(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 23:00', 'Asia/Makassar'));
        $this->artisan('ai:retry-stuck', ['--tenant' => 'griya-pelangi'])->expectsOutputToContain('jam malam')->assertSuccessful();
        $this->artisan('ai:retry-stuck', ['--tenant' => 'tidak-ada'])->assertFailed();
    }

    // ---- 2. "Tanyakan tipe lewat daftar" ---------------------------------------------------------------------

    private function system(): string
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['system'];
    }

    public function test_by_default_the_ai_asks_which_type_with_the_list(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
        $this->in(fn () => [Property::create(['name' => 'Tulip', 'status' => 'available']), Property::create(['name' => 'Diamond Bougen', 'status' => 'available'])]);

        $this->says('Halo kak');

        $this->assertStringContainsString('sebutkan pilihannya: Diamond Bougen, Tulip', $this->system());
        $this->assertStringContainsString('Jangan menyebut harga sebelum', $this->system());
    }

    public function test_switched_off_the_ai_offers_no_list_and_may_name_the_general_price(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
        $this->settings(['product_list' => false]);
        $this->in(fn () => [Property::create(['name' => 'Tulip', 'status' => 'available']), Property::create(['name' => 'Diamond Bougen', 'status' => 'available'])]);

        $this->says('Halo kak');

        $system = $this->system();
        $this->assertStringNotContainsString('sebutkan pilihannya', $system);
        $this->assertStringNotContainsString('Jangan menyebut harga sebelum', $system);
        $this->assertStringContainsString('Jangan menyodorkan daftar atau pilihan bernomor', $system);
        $this->assertStringContainsString('PRODUK: <id>', $system, 'A type the client names is still recorded');
        $this->assertTrue(AiSettings::of(new Tenant)->asksProductWithList(), 'On unless turned off');
    }
}
