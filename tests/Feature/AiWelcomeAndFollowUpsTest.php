<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\RunFollowUps;
use App\Ai\AiSettings;
use App\Enums\AiMode;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Filament\Resources\FollowUpRules\Pages\ManageFollowUpRules;
use App\FollowUps\PropertyFollowUps;
use App\FollowUps\SurveyReminder;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 4 of the property AI: the brochure with the first automatic reply, the built-in property follow-ups in one
 * click (stopping once a person took over), and how long the AI stays out of a chat a person is handling.
 */
class AiWelcomeAndFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WaChannel $channel;

    private int $n = 0;

    private string $answer = 'Halo Kak 😊 Tipe Tulip mulai 600 jutaan ya.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
        $this->tenant = $this->makeTenant('griya-pelangi');
        $this->tenant->forceFill(['name' => 'Rumahkudotcom', 'timezone' => 'Asia/Makassar'])->save();
        $this->channel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Nomor utama', 'phone' => '+6289900000001',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Harga', 'content' => 'Tipe Tulip mulai 600 jutaan.']));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->answer]]]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n, 'ok' => true]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->tenant, $fn);
    }

    private function settings(array $values): void
    {
        $this->tenant->forceFill(['ai_settings' => AiSettings::fromForm($values + AiSettings::of(null)->form())])->save();
    }

    private function says(string $text, string $from = '6289900001234', bool $fromMe = false): ?WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($from), 'text', $text, null, CarbonImmutable::now(), 'Rina', fromMe: $fromMe,
        )));
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->where('from_phone', false)->orderBy('id')->pluck('body')->all());
    }

    private function system(): string
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['system'];
    }

    private function asset(string $title, string $url): MediaAsset
    {
        return $this->in(fn () => MediaAsset::create(['folder_id' => MediaFolder::query()->firstOrCreate(['name' => 'Brosur'])->getKey(),
            'title' => $title, 'kind' => 'link', 'url' => $url, 'active' => true]));
    }

    // ---- (13) Welcome brochure and price list --------------------------------------------------------------------

    public function test_the_first_automatic_reply_carries_the_brochure_and_price_list_once(): void
    {
        $brochure = $this->asset('Brosur Green Tara', 'https://contoh.test/brosur.pdf');
        $prices = $this->asset('Daftar harga', 'https://contoh.test/harga.pdf');
        $this->settings(['welcome_assets' => [$brochure->getKey(), $prices->getKey()]]);

        $this->says('Halo kak, info rumahnya');

        $this->assertStringContainsString('LAMPIRAN SAMBUTAN', $this->system());
        $this->assertStringContainsString('Brosur Green Tara, Daftar harga', $this->system());
        $this->assertSame([$this->answer, $brochure->linkText(), $prices->linkText()], $this->sent());
        $lead = $this->in(fn () => Lead::query()->firstOrFail());
        $this->assertNotNull($lead->ai_welcome_sent_at);

        // Never again for the same lead.
        $this->says('Lokasinya di mana kak?');
        $this->assertStringNotContainsString('LAMPIRAN SAMBUTAN', $this->system());
        $this->assertSame([$this->answer, $brochure->linkText(), $prices->linkText(), $this->answer], $this->sent());
    }

    public function test_no_welcome_attachment_by_default_in_draft_mode_or_for_a_lead_the_ai_already_answered(): void
    {
        $this->says('Halo kak');
        $this->assertSame([$this->answer], $this->sent(), 'Nothing chosen: nothing attached');

        // A lead the AI answered before the setting was chosen does not get the welcome later.
        $brochure = $this->asset('Brosur', 'https://contoh.test/brosur.pdf');
        $this->settings(['welcome_assets' => [$brochure->getKey()]]);
        $this->says('Harganya?');
        $this->assertSame([$this->answer, $this->answer], $this->sent());

        // Draft mode: the agent decides what to send.
        $this->channel->forceFill(['ai_mode' => AiMode::Draft])->save();
        $this->says('Halo', '6289900005678');
        $draft = $this->in(fn () => AiDraft::query()->latest('id')->firstOrFail());
        $this->assertSame(AiDraft::PENDING, $draft->status);
        $this->assertNull($draft->media_asset_ids);
    }

    // ---- (14) Built-in property follow-ups in one click ----------------------------------------------------------

    public function test_one_click_switches_the_property_follow_ups_on_and_keeps_the_agencys_wording(): void
    {
        $this->in(function () {
            FollowUpRule::query()->where('name', 'Sapaan H+1')->update(['body' => 'Teks agensi sendiri {nama}']);
            FollowUpRule::query()->where('name', 'Tawarkan survei H+7')->delete();
        });
        $this->assertFalse($this->in(fn () => PropertyFollowUps::for($this->tenant)->enabled()));

        $result = $this->in(fn () => PropertyFollowUps::for($this->tenant)->enable());

        $this->assertSame(['Sapaan H+1', 'Tanya kebutuhan H+3', 'Tawarkan survei H+7', 'Pengingat survei H-1'], $result['on']);
        $this->assertSame([], $result['missing']);
        $this->in(function () {
            $this->assertSame(3, FollowUpRule::query()->whereNull('kind')->where('active', true)->count());
            $this->assertSame('Teks agensi sendiri {nama}', FollowUpRule::query()->where('name', 'Sapaan H+1')->value('body'));
            $this->assertSame(7, FollowUpRule::query()->where('name', 'Tawarkan survei H+7')->value('delay_days'));
            $this->assertTrue(SurveyReminder::rule()->active);
            $this->assertTrue(PropertyFollowUps::for($this->tenant)->enabled());
        });

        // Twice is the same as once.
        $this->in(fn () => PropertyFollowUps::for($this->tenant)->enable());
        $this->assertSame(4, $this->in(fn () => FollowUpRule::query()->count()));
    }

    public function test_the_follow_up_page_offers_the_one_click_to_a_property_admin(): void
    {
        $this->actingAs($this->tenant->users()->first());
        $this->in(fn () => Livewire::test(ManageFollowUpRules::class)
            ->assertSee('Follow-up properti bawaan')
            ->callAction('propertyFollowUps')
            ->assertNotified('4 follow-up aktif'));

        $this->assertTrue($this->in(fn () => PropertyFollowUps::for($this->tenant)->enabled()));
    }

    private function newLead(string $phone): Lead
    {
        return $this->in(fn () => Lead::query()->create([
            'name' => 'Klien Contoh', 'phone' => $phone,
            'stage_id' => Stage::query()->where('name', 'Lead baru')->value('id'),
            'stage_entered_at' => CarbonImmutable::parse('2026-11-15 08:00', 'Asia/Makassar'),
        ]));
    }

    public function test_the_sequence_stops_once_a_person_answered_from_the_inbox_or_the_phone(): void
    {
        $this->in(fn () => PropertyFollowUps::for($this->tenant)->enable());
        $quiet = $this->newLead('+6289900001111');
        $inbox = $this->newLead('+6289900002222');
        $phone = $this->newLead('+6289900003333');

        $this->in(function () use ($inbox) {
            $conversation = WaConversation::query()->create(['lead_id' => $inbox->getKey(), 'phone' => $inbox->phone]);
            $conversation->messages()->create(['channel_id' => $this->channel->getKey(), 'direction' => 'out', 'type' => 'text', 'body' => 'Halo kak, saya bantu ya',
                'status' => MessageStatus::Sent, 'sent_at' => CarbonImmutable::parse('2026-11-15 12:00', 'Asia/Makassar'), 'origin' => PropertyFollowUps::AGENT_ORIGIN]);
        });
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-15 12:00', 'Asia/Makassar'));
        $this->says('Halo kak, saya Susan', '6289900003333', fromMe: true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));

        $result = app(RunFollowUps::class)($this->tenant);

        $this->assertSame(1, $result['sent']);
        $this->in(function () use ($quiet, $inbox, $phone) {
            $this->assertSame('sent', FollowUpLog::query()->where('lead_id', $quiet->getKey())->value('status'));
            $this->assertSame('Agen sudah menangani chat ini', FollowUpLog::query()->where('lead_id', $inbox->getKey())->value('note'));
            $this->assertSame('Agen sudah menangani chat ini', FollowUpLog::query()->where('lead_id', $phone->getKey())->value('note'));
            // The follow-up itself is marked, so it never reads as a person taking over.
            $this->assertSame('follow_up', WaMessage::query()->where('direction', 'out')->where('is_intro', false)->whereHas('conversation', fn ($q) => $q->where('lead_id', $quiet->getKey()))->value('origin'));
        });
    }

    public function test_a_shops_follow_ups_still_go_after_an_agent_wrote(): void
    {
        $shop = $this->makeTenant('toko-contoh');
        $shop->forceFill(['industry' => 'retail'])->save();
        $shop->refresh();

        $this->assertFalse(PropertyFollowUps::stopsOnTakeover($shop));
        $this->assertTrue(PropertyFollowUps::stopsOnTakeover($this->tenant));
    }

    // ---- (15) The AI stays out of a chat a person is handling ----------------------------------------------------

    public function test_the_pause_after_an_agent_wrote_follows_the_agency_setting(): void
    {
        $this->says('Halo kak');
        $conversation = $this->in(fn () => WaConversation::query()->firstOrFail());

        $this->says('Saya bantu ya kak', fromMe: true);
        $this->assertSame(300, (int) now()->diffInSeconds($conversation->refresh()->ai_paused_until), 'Default: 5 minutes, as before');

        $this->settings(['agent_pause' => 30]);
        $this->says('Sebentar kak', fromMe: true);
        $this->assertSame(1800, (int) now()->diffInSeconds($conversation->refresh()->ai_paused_until));

        // While paused, the AI does not send; its answer waits for the agent.
        $this->says('Harganya berapa?');
        $draft = $this->in(fn () => AiDraft::query()->latest('id')->firstOrFail());
        $this->assertSame(AiDraft::PENDING, $draft->status);
        $this->assertSame([$this->answer], $this->sent());
    }

    public function test_a_handed_off_chat_can_stay_with_the_agent(): void
    {
        $this->says('Saya WNA, bisa beli?');
        $conversation = $this->in(fn () => WaConversation::query()->firstOrFail());
        $this->assertSame(AiDraft::HANDOFF, $this->in(fn () => AiDraft::query()->latest('id')->value('status')));
        $this->assertFalse($conversation->refresh()->aiPaused(), 'Default: a handoff does not pause the AI');

        $this->settings(['handoff_pause' => 60]);
        $this->says('Kalau orang asing gimana kak?');
        $this->assertTrue($conversation->refresh()->aiPaused());
        $this->assertSame(3600, (int) now()->diffInSeconds($conversation->ai_paused_until));

        $this->says('Harganya berapa?');
        $this->assertSame([], $this->sent(), 'The next question waits for the agent');
    }

    public function test_the_settings_form_saves_the_welcome_items_and_pauses(): void
    {
        $brochure = $this->asset('Brosur', 'https://contoh.test/brosur.pdf');
        $this->actingAs($this->tenant->users()->first());

        $this->in(fn () => Livewire::test(ManageAiKnowledge::class)
            ->callAction('settings', ['welcome_assets' => [$brochure->getKey()], 'agent_pause' => 10, 'handoff_pause' => 45])
            ->assertHasNoActionErrors());

        $settings = AiSettings::of($this->tenant->refresh());
        $this->assertSame([$brochure->getKey()], $settings->welcomeAssets());
        $this->assertSame(600, $settings->agentPauseSeconds());
        $this->assertSame(45, $settings->handoffPauseMinutes());
        $this->assertSame(300, AiSettings::of(new Tenant)->agentPauseSeconds(), 'Unset: the configured 5 minutes');
        $this->assertSame(0, AiSettings::of(new Tenant)->handoffPauseMinutes());
    }
}
