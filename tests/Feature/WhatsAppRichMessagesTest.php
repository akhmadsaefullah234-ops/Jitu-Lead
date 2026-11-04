<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Ai\Transcriber;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Models\AiDraft;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Voice notes, stickers, videos, locations, contact cards and reactions from WhatsApp: kept, shown in the
 * Inbox, and handled by the AI without guessing (a sticker or reaction is never a question; a voice note the
 * AI cannot hear gets a polite request to type and a note for the agent).
 */
class WhatsAppRichMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->makeTenant();
        config(['services.anthropic.key' => 'test-key', 'services.transcribe.provider' => null, 'services.transcribe.key' => null]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Ongkir ke Bekasi kami cek dulu ya, Kak.']]]),
            'gw.example.com/media/*' => Http::response(self::mp4(), 200, ['Content-Type' => 'application/octet-stream']),
            'gw.example.com/*' => fn () => Http::response(['id' => 'out-'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private static function ogg(): string
    {
        return 'OggS'.str_repeat("\0", 60);
    }

    private static function webp(): string
    {
        return 'RIFF'.pack('V', 30).'WEBPVP8X'.str_repeat("\0", 30);
    }

    private static function mp4(): string
    {
        return "\0\0\0\x18ftypmp42".str_repeat("\0", 60);
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function gateway(?AiMode $mode = null): WaChannel
    {
        return $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode ?? AiMode::Off,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    /** @param  array<string, mixed>  $fields */
    private function webhook(WaChannel $channel, string $type, array $fields = [], ?string $id = null): ?WaMessage
    {
        $id ??= 'g'.++$this->n;
        $body = json_encode(['event' => 'message', 'id' => $id, 'from' => '081299990000', 'type' => $type, 'name' => 'Budi', 'timestamp' => time()] + $fields);

        $this->call('POST', "/webhooks/whatsapp/gateway/{$channel->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body)->assertOk();

        return $this->inTenant(fn () => WaMessage::query()->where('external_id', $id)->first());
    }

    private function voiceNote(WaChannel $channel, int $seconds = 23, ?string $data = null): WaMessage
    {
        return $this->webhook($channel, 'audio', ['media' => ['mime_type' => 'audio/ogg; codecs=opus', 'seconds' => $seconds, 'ptt' => true, 'data' => base64_encode($data ?? self::ogg())]]);
    }

    private function inbox(WaMessage $message)
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $lead = $this->inTenant(fn () => $message->conversation->lead);

        return Livewire::test(Inbox::class, ['leadId' => $lead->getKey()]);
    }

    private function aiCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'))->count();
    }

    private function draftFor(WaMessage $m): ?AiDraft
    {
        return $this->inTenant(fn () => AiDraft::where('message_id', $m->getKey())->first());
    }

    // --- Kept and shown ---

    public function test_a_voice_note_is_kept_and_played_in_the_inbox(): void
    {
        $message = $this->voiceNote($this->gateway());

        $this->assertSame('audio', $message->type);
        $this->assertSame('audio/ogg', $message->media['mime_type']);
        $this->assertSame(23, $message->media['seconds']);
        $this->assertTrue($message->media['ptt']);
        Storage::disk('local')->assertExists($message->media['path']);

        $url = route('wa.media', $message->getKey());
        $this->inbox($message)
            ->assertSeeHtml('<audio controls preload="none" src="'.$url.'"')
            ->assertSee('🎤 Voice note 0:23')
            ->assertSee('🎤 Voice note');

        // Played in the page, not downloaded
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/ogg')->assertHeader('Content-Disposition', 'inline');
    }

    public function test_a_sticker_is_shown_small_without_a_bubble_and_only_real_webp_is_kept(): void
    {
        $channel = $this->gateway();
        $sticker = $this->webhook($channel, 'sticker', ['media' => ['mime_type' => 'image/webp', 'animated' => true, 'data' => base64_encode(self::webp())]]);

        $this->assertSame('image/webp', $sticker->media['mime_type']);
        $this->assertTrue($sticker->media['animated']);
        $this->inbox($sticker)->assertSeeHtml('class="wa-m')->assertSeeHtml(' sticker ')->assertSeeHtml('alt="Stiker"')->assertSee('Stiker');

        $fake = $this->webhook($channel, 'sticker', ['media' => ['mime_type' => 'image/webp', 'data' => base64_encode('<?php echo 1;')]]);
        $this->assertSame('unsupported', $fake->media['error']);
    }

    public function test_a_video_is_kept_and_played_with_its_caption(): void
    {
        $video = $this->webhook($this->gateway(), 'video', ['text' => 'tampak depan', 'media' => ['mime_type' => 'video/mp4', 'seconds' => 12, 'data' => base64_encode(self::mp4())]]);

        $this->assertSame('video/mp4', $video->media['mime_type']);
        $this->inbox($video)
            ->assertSeeHtml('<video controls preload="metadata" playsinline src="'.route('wa.media', $video->getKey()).'"')
            ->assertSee('tampak depan')
            ->assertSee('🎥 Video: tampak depan');
    }

    public function test_a_video_too_big_for_the_webhook_is_fetched_from_the_gateway(): void
    {
        $video = $this->webhook($this->gateway(), 'video', ['media' => ['mime_type' => 'video/mp4', 'bytes' => 9000000, 'seconds' => 40, 'fetch' => true]], 'BIGVIDEO1');
        $video->refresh();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://gw.example.com/media/BIGVIDEO1' && $r->hasHeader('X-Session-Id'));
        $this->assertSame('video/mp4', $video->media['mime_type']);
        $this->assertSame(40, $video->media['seconds']);
        Storage::disk('local')->assertExists($video->media['path']);
    }

    public function test_media_that_could_not_be_had_says_so_clearly(): void
    {
        $channel = $this->gateway();
        $big = $this->webhook($channel, 'audio', ['media' => ['mime_type' => 'audio/ogg', 'ptt' => true, 'seconds' => 900, 'error' => 'too_large']]);
        $this->webhook($channel, 'video', ['media' => ['mime_type' => 'video/mp4', 'error' => 'download_failed']]);
        $this->webhook($channel, 'sticker', ['media' => ['mime_type' => 'image/webp', 'error' => 'too_large']]);

        $this->inbox($big)
            ->assertSee('[Voice note tidak bisa diputar: ukurannya terlalu besar]')
            ->assertSee('[Video tidak bisa diputar: gagal diunduh dari WhatsApp]')
            ->assertSee('[Stiker tidak bisa ditampilkan: ukurannya terlalu besar]')
            ->assertDontSee('[audio]')
            ->assertDontSee('[unsupported]');
    }

    public function test_a_location_is_a_card_with_a_google_maps_link(): void
    {
        $message = $this->webhook($this->gateway(), 'location', ['location' => ['latitude' => -6.2, 'longitude' => 106.81, 'name' => 'Rumah Budi', 'address' => 'Jl. Melati 3, Bekasi']]);

        $this->assertSame(-6.2, $message->media['latitude']);
        $this->inbox($message)
            ->assertSee('📍 Rumah Budi · Jl. Melati 3, Bekasi')
            ->assertSeeHtml('href="https://maps.google.com/?q=-6.2,106.81"')
            ->assertSee('📍 Lokasi');
    }

    public function test_a_contact_card_shows_the_name_and_number_with_a_copy_button(): void
    {
        $message = $this->webhook($this->gateway(), 'contacts', ['contacts' => [['name' => 'Siti Aminah', 'phones' => ['6281234567890']]]]);

        $this->inbox($message)->assertSee('Siti Aminah')->assertSee('6281234567890')->assertSee('Salin')->assertSee('👤 Kontak');
    }

    public function test_a_reaction_goes_on_the_message_it_reacts_to_and_is_no_bubble_of_its_own(): void
    {
        $channel = $this->gateway();
        $original = $this->webhook($channel, 'text', ['text' => 'Ada unit tipe 36?'], 'ORIG1');

        $this->assertNull($this->webhook($channel, 'reaction', ['reaction' => ['message_id' => 'ORIG1', 'emoji' => '👍']], 'R1'));
        $this->assertSame(['in' => '👍'], $original->refresh()->reactions);
        $this->assertSame(1, $this->inTenant(fn () => WaMessage::query()->count()));

        $this->inbox($original)->assertSeeHtml('class="wa-react"')->assertSee('👍');

        // Taken back
        $this->webhook($channel, 'reaction', ['reaction' => ['message_id' => 'ORIG1', 'emoji' => '']], 'R2');
        $this->assertNull($original->refresh()->reactions);

        // A reaction to a message the CRM does not have is dropped
        $this->webhook($channel, 'reaction', ['reaction' => ['message_id' => 'UNKNOWN', 'emoji' => '❤']], 'R3');
        $this->assertSame(1, $this->inTenant(fn () => WaMessage::query()->count()));
    }

    public function test_official_api_voice_notes_stickers_and_videos_are_downloaded_from_meta(): void
    {
        Http::fake([
            'graph.facebook.com/*/AUD1' => Http::response(['url' => 'https://lookaside.fbsbx.com/a']),
            'graph.facebook.com/*/STK1' => Http::response(['url' => 'https://lookaside.fbsbx.com/s']),
            'graph.facebook.com/*/VID1' => Http::response(['url' => 'https://lookaside.fbsbx.com/v']),
            'https://lookaside.fbsbx.com/a' => Http::response(self::ogg()),
            'https://lookaside.fbsbx.com/s' => Http::response(self::webp()),
            'https://lookaside.fbsbx.com/v' => Http::response(self::mp4()),
        ]);
        $channel = $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Official, 'name' => 'Resmi', 'phone' => '+6281100000001', 'status' => WaChannelStatus::Connected, 'position' => 0,
            'credentials' => ['phone_number_id' => '111', 'access_token' => 'tok', 'app_secret' => 'sekret', 'verify_token' => 'vt'],
        ]));
        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '111'],
            'messages' => [
                ['from' => '6281299990000', 'id' => 'wamid.a', 'timestamp' => (string) time(), 'type' => 'audio', 'audio' => ['id' => 'AUD1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]],
                ['from' => '6281299990000', 'id' => 'wamid.s', 'timestamp' => (string) time(), 'type' => 'sticker', 'sticker' => ['id' => 'STK1', 'mime_type' => 'image/webp', 'animated' => false]],
                ['from' => '6281299990000', 'id' => 'wamid.v', 'timestamp' => (string) time(), 'type' => 'video', 'video' => ['id' => 'VID1', 'mime_type' => 'video/mp4']],
                ['from' => '6281299990000', 'id' => 'wamid.r', 'timestamp' => (string) time(), 'type' => 'reaction', 'reaction' => ['message_id' => 'wamid.a', 'emoji' => '🙏']],
            ],
        ]]]]]]);

        $this->call('POST', "/webhooks/whatsapp/official/{$channel->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'sekret'),
        ]), $body)->assertOk();

        $by = $this->inTenant(fn () => WaMessage::query()->get()->keyBy('external_id'));
        $this->assertCount(3, $by, 'the reaction is not a message of its own');
        $this->assertSame('audio/ogg', $by['wamid.a']->media['mime_type']);
        $this->assertTrue($by['wamid.a']->media['ptt']);
        $this->assertSame(['in' => '🙏'], $by['wamid.a']->reactions);
        $this->assertSame('image/webp', $by['wamid.s']->media['mime_type']);
        $this->assertSame('video/mp4', $by['wamid.v']->media['mime_type']);
        foreach ($by as $m) {
            Storage::disk('local')->assertExists($m->media['path']);
        }
    }

    // --- AI ---

    public function test_stickers_and_reactions_never_start_an_ai_reply(): void
    {
        $channel = $this->gateway(AiMode::Auto);
        $sticker = $this->webhook($channel, 'sticker', ['media' => ['mime_type' => 'image/webp', 'data' => base64_encode(self::webp())]], 'S1');
        $this->webhook($channel, 'reaction', ['reaction' => ['message_id' => 'S1', 'emoji' => '😂']]);

        $this->assertSame(0, $this->aiCalls());
        $this->assertNull($this->draftFor($sticker));
        $this->assertNull($this->inTenant(fn () => app(GenerateAiReply::class)($sticker->refresh())));
    }

    public function test_a_voice_note_without_transcription_asks_to_type_and_leaves_a_note_for_the_agent(): void
    {
        $channel = $this->gateway(AiMode::Auto);
        $message = $this->voiceNote($channel);

        $this->assertSame(0, $this->aiCalls(), 'the model is never asked to guess what was said');
        $draft = $this->draftFor($message);
        $this->assertSame(AiDraft::SENT, $draft->status);
        $this->assertSame(GenerateAiReply::VOICE_NOTE_REPLY, $draft->body);
        $this->assertFalse($draft->api_call);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['text'] === GenerateAiReply::VOICE_NOTE_REPLY);

        $note = $this->inTenant(fn () => LeadActivity::query()->where('type', 'note')->latest('id')->first());
        $this->assertStringContainsString('voice note (0:23)', $note->body);
        $this->assertStringContainsString('dengarkan', $note->body);

        // A second voice note in the same hour is not answered again; it goes to the agent.
        $second = $this->voiceNote($channel, 8);
        $this->assertSame(AiDraft::HANDOFF, $this->draftFor($second)->status);
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/messages'))->count());
    }

    public function test_draft_mode_offers_the_request_to_type_for_the_agent_to_send(): void
    {
        $draft = $this->draftFor($this->voiceNote($this->gateway(AiMode::Draft)));

        $this->assertSame(AiDraft::PENDING, $draft->status);
        $this->assertSame(GenerateAiReply::VOICE_NOTE_REPLY, $draft->body);
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/messages'))->count());
    }

    public function test_a_transcribed_voice_note_is_answered_from_what_was_said_and_shown_under_the_player(): void
    {
        $heard = [];
        $this->app->instance(Transcriber::class, new class($heard) implements Transcriber
        {
            public function __construct(private array &$heard) {}

            public function configured(): bool
            {
                return true;
            }

            public function transcribe(string $bytes, string $filename): ?string
            {
                $this->heard[] = $filename;

                return 'Kak, ongkir ke Bekasi berapa ya?';
            }
        });

        $message = $this->voiceNote($this->gateway(AiMode::Draft));

        $this->assertSame('Kak, ongkir ke Bekasi berapa ya?', $message->refresh()->transcript);
        $this->assertStringEndsWith('.ogg', $heard[0]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com') && str_contains(json_encode($r['messages']), 'ongkir ke Bekasi berapa'));
        $this->assertSame('Ongkir ke Bekasi kami cek dulu ya, Kak.', $this->draftFor($message)->body);

        $this->inbox($message)->assertSee('Kak, ongkir ke Bekasi berapa ya?');

        // Longer than two minutes: left for an agent
        $long = $this->voiceNote($this->inTenant(fn () => WaChannel::query()->first()), 300);
        $this->assertNull($long->refresh()->transcript);
        $this->assertCount(1, $heard);
        $this->assertSame(GenerateAiReply::VOICE_NOTE_REPLY, $this->draftFor($long)->body);
    }

    public function test_the_openai_style_transcriber_sends_indonesian_audio_and_is_off_without_a_key(): void
    {
        $transcriber = app(Transcriber::class);
        $this->assertFalse($transcriber->configured());

        config(['services.transcribe.provider' => 'groq', 'services.transcribe.key' => 'gsk']);
        Http::fake(['api.groq.com/*' => Http::response(['text' => ' Halo kak '])]);

        $this->assertTrue($transcriber->configured());
        $this->assertSame('Halo kak', $transcriber->transcribe(self::ogg(), 'a.ogg'));
        $this->assertNull($transcriber->transcribe('#!AMR', 'a.amr'), 'formats the endpoint cannot read are left alone');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.groq.com/openai/v1/audio/transcriptions' && str_contains($r->body(), 'name="language"') && str_contains($r->body(), "\r\n\r\nid\r\n"));
    }

    public function test_a_location_reaches_the_ai_as_text_it_can_use_for_the_address(): void
    {
        $message = $this->webhook($this->gateway(AiMode::Draft), 'location', ['location' => ['latitude' => -6.2, 'longitude' => 106.81, 'name' => 'Rumah Budi', 'address' => 'Jl. Melati 3, Bekasi']]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com')
            && str_contains(collect($r['messages'])->last()['content'], 'Pelanggan mengirim lokasi: Rumah Budi, Jl. Melati 3, Bekasi, koordinat -6.2,106.81'));
        $this->assertSame(AiDraft::PENDING, $this->draftFor($message)->status);
    }

    public function test_a_lead_from_a_voice_note_is_still_created(): void
    {
        $this->voiceNote($this->gateway());

        $this->assertSame(1, $this->inTenant(fn () => Lead::query()->count()));
    }
}
