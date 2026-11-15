<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\HandleInboundWhatsApp;
use App\Ai\AiSettings;
use App\Ai\PropertyGuard;
use App\Ai\PropertyTools;
use App\Ai\ReplySignals;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ShowTyping;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayProvider;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AiStyleAndGuardTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWLEDGE = 'Tipe Tulip mulai 600 jutaan. Biaya awal cukup 10 juta. Legalitas SHGB, leasehold 20 tahun. Freehold/SHM belum tersedia. Unit inden, show unit sudah ada. Lokasi di Jl. Provinsi Cokroaminoto, Denpasar Utara.';

    private Tenant $tenant;

    private WaChannel $channel;

    private int $n = 0;

    private bool $gatewayDown = false;

    private string $answer = 'Baik Kak 😊';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
        $this->tenant = $this->makeTenant('griya-pelangi');
        $this->tenant->forceFill(['name' => 'Rumahkudotcom', 'timezone' => 'Asia/Makassar'])->save();
        $this->channel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Susan', 'phone' => '+6289900000001',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Green Tara Residence', 'content' => self::KNOWLEDGE]));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->answer]]]),
            'gw.example.com/*' => fn () => $this->gatewayDown ? Http::response(['error' => 'Tidak ditemukan.'], 404) : Http::response(['id' => 'g'.++$this->n, 'ok' => true]),
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

    private function system(): string
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['system'];
    }

    // ---- Format ------------------------------------------------------------------------------------------------

    public function test_an_agency_that_changed_nothing_keeps_short_replies(): void
    {
        $this->answer = "**Harga** mulai 600 jutaan\n- Tulip";
        $this->says('Harga berapa?');

        $this->assertStringContainsString('Satu sampai tiga kalimat, tanpa markdown.', $this->system());
        $this->assertSame("**Harga** mulai 600 jutaan\n- Tulip", collect($this->sent())->last(), 'Nothing is reshaped without the setting');
    }

    public function test_whatsapp_format_is_allowed_shaped_and_kept_within_the_line_limit(): void
    {
        $this->settings(['format' => 'whatsapp', 'max_lines' => 4]);
        $this->answer = "Hai Kaka ☺ Kenalin, aku dari _Green Tara Residence_ ✨\n\n**Skema harga:**\n1. Tipe Tulip mulai *600 jutaan*\n2. Biaya awal *10 juta sajah*\n3. Leasehold 20 tahun\n4. Baris kelima";

        $this->says('Halo kak, info harga dong');

        $this->assertStringContainsString('Boleh memakai format WhatsApp', $this->system());
        $this->assertStringContainsString('Paling banyak 4 baris', $this->system());
        $this->assertStringNotContainsString('tanpa markdown', $this->system());
        $this->assertSame("Hai Kaka ☺ Kenalin, aku dari _Green Tara Residence_ ✨\n\n*Skema harga:*\n1. Tipe Tulip mulai *600 jutaan*\n2. Biaya awal *10 juta sajah*", collect($this->sent())->last());
    }

    public function test_the_internal_text_check_accepts_whatsapp_formatting(): void
    {
        $this->assertFalse(ReplySignals::internal("Salam kenal ka Rina, aku bantu rincikan yaaa😊\n\n*Legalitas:*\n1. SHGB, _leasehold_ 20 tahun\n2. Bangunan 100% milik Kaka 🙏"));
        $this->assertFalse(ReplySignals::internal('• Lokasi: Jl. Provinsi Cokroaminoto, Denpasar Utara ✨'));
        $this->assertTrue(ReplySignals::internal('Saya perlu memanggil '.PropertyTools::SURVEY.' dulu.'));
    }

    // ---- Guard -------------------------------------------------------------------------------------------------

    public function test_the_guard_holds_numbers_and_phrases_that_are_not_in_the_knowledge(): void
    {
        $given = self::KNOWLEDGE."\nSekarang Senin, 16 November 2026";
        $phrases = ['siap huni', 'SHM', 'pasti disetujui', 'gratis'];
        $problems = fn (string $answer) => PropertyGuard::problems($answer, $given, $phrases);

        $this->assertSame([], $problems('Tipe Tulip mulai *600 jutaan*, biaya awal cukup *10 juta sajah* 😊'));
        $this->assertSame([], $problems('Legalitasnya SHGB, leasehold 20 tahun ya kak. Freehold/SHM belum tersedia.'));
        $this->assertSame([], $problems('Unitnya inden, belum siap huni kak, tapi show unit sudah ada.'));
        $this->assertNotSame([], $problems('Cicilannya cuma Rp 5 juta per bulan kak'));
        $this->assertNotSame([], $problems('Harga Tulip 650 juta kak'));
        $this->assertNotSame([], $problems('DP 30% saja kak'));
        $this->assertNotSame([], $problems('Luas tanahnya 120 m2'));
        $this->assertNotSame([], $problems('Serah terima tahun 2027'));
        $this->assertNotSame([], $problems('Bisa perpanjang 30 tahun'));
        $this->assertNotSame([], $problems('Unitnya siap huni kak'));
        $this->assertNotSame([], $problems('Sudah SHM kak'));
        $this->assertNotSame([], $problems('KPR pasti disetujui'));
        $this->assertSame([], $problems('Tipe 45 dengan 2 kamar, 1 KM'), 'Bare numbers are not money');
    }

    public function test_a_held_answer_is_not_sent_and_waits_for_an_agent_with_its_reason(): void
    {
        $this->settings(['guard' => true, 'forbidden_phrases' => 'siap huni, pasti disetujui']);
        $this->answer = 'Unitnya siap huni kak, cicilan cuma Rp 5 juta per bulan 😊';

        $message = $this->says('Unitnya sudah bisa ditempati? Cicilan berapa?');

        $this->assertSame([], $this->sent());
        $draft = $this->in(fn () => AiDraft::query()->where('message_id', $message->getKey())->first());
        $this->assertSame(AiDraft::PENDING, $draft->status);
        $this->assertStringContainsString('Ditahan pemeriksa', $draft->reason);
        $this->assertSame($this->answer, $draft->body);
        $activity = $this->in(fn () => Lead::query()->first()->activities()->where('type', 'ai_held')->first());
        $this->assertStringContainsString('frasa terlarang "siap huni"', $activity->body);
        $this->assertStringContainsString('Rp 5 juta', $activity->body);
    }

    public function test_an_answer_from_the_knowledge_passes_the_guard(): void
    {
        $this->settings(['guard' => true, 'forbidden_phrases' => 'siap huni']);
        $this->answer = 'Tipe Tulip mulai *600 jutaan* kak, biaya awal cukup 10 juta 😊';

        $this->says('Harga Tulip berapa?');

        $this->assertSame([$this->answer], $this->sent());
    }

    public function test_without_the_guard_nothing_is_held(): void
    {
        $this->answer = 'Cicilan cuma Rp 5 juta per bulan kak';
        $this->says('Cicilan berapa?');

        $this->assertSame([$this->answer], $this->sent());
    }

    public function test_a_new_property_agency_hands_off_foreigners_and_notaries(): void
    {
        $this->says('Saya WNA, bisa beli unitnya?');
        $this->says('Notarisnya siapa kak?');

        $this->assertSame([], $this->sent());
        $this->assertSame(2, $this->in(fn () => AiDraft::query()->where('status', AiDraft::HANDOFF)->count()));
    }

    // ---- Pause, typing, quiet hours ----------------------------------------------------------------------------

    public function test_an_automatic_reply_waits_a_random_pause_with_typing_shown(): void
    {
        Queue::fake([GenerateAiReplyJob::class, ShowTyping::class]);
        $this->settings(['delay_min' => 5, 'delay_max' => 25]);

        $this->says('Halo kak');

        Queue::assertPushed(GenerateAiReplyJob::class, function (GenerateAiReplyJob $job) {
            $seconds = now()->diffInSeconds($job->delay);

            return $seconds >= 5 && $seconds <= 25;
        });
        Queue::assertPushed(ShowTyping::class, fn (ShowTyping $job) => $job->phone === '+6289900001234');
    }

    public function test_no_pause_by_default_or_in_draft_mode(): void
    {
        Queue::fake([GenerateAiReplyJob::class, ShowTyping::class]);
        $this->says('Halo kak');
        Queue::assertPushed(GenerateAiReplyJob::class, fn (GenerateAiReplyJob $job) => $job->delay === null);

        $this->settings(['delay_min' => 5, 'delay_max' => 25]);
        $this->channel->forceFill(['ai_mode' => AiMode::Draft])->save();
        $this->says('Halo lagi');
        Queue::assertPushed(GenerateAiReplyJob::class, 2);
        Queue::assertNotPushed(ShowTyping::class);
    }

    public function test_typing_is_shown_through_the_gateway_and_an_old_gateway_is_no_error(): void
    {
        $this->assertTrue((new GatewayProvider($this->channel))->typing('+6289900001234'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/presence') && $r['state'] === 'composing' && $r['to'] === '+6289900001234');

        $this->gatewayDown = true;
        $this->assertFalse((new GatewayProvider($this->channel))->typing('+6289900001234'));
    }

    public function test_at_night_the_client_is_greeted_once_and_answered_in_the_morning(): void
    {
        Queue::fake([GenerateAiReplyJob::class]);
        $this->settings(['quiet_from' => '21:00', 'quiet_until' => '07:00', 'quiet_mode' => 'greet', 'quiet_message' => 'Terima kasih kak, kami balas besok pagi ya 🙏']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 23:00', 'Asia/Makassar'));

        $first = $this->says('Malam kak, harga Tulip berapa?');
        $this->in(fn () => app(GenerateAiReply::class)($first));
        $second = $this->says('Sama lokasinya di mana?');
        $this->in(fn () => app(GenerateAiReply::class)($second));

        $this->assertSame(['Terima kasih kak, kami balas besok pagi ya 🙏'], $this->sent(), 'Greeted once per night');
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->count());
        Queue::assertPushed(GenerateAiReplyJob::class, function (GenerateAiReplyJob $job) use ($second) {
            if (! $job->afterHours || $job->delay === null) {
                return false;
            }
            $at = CarbonImmutable::instance($job->delay)->setTimezone('Asia/Makassar');

            return $job->messageId === $second->getKey() && $at->format('Y-m-d') === '2026-11-17' && $at->format('H') === '07';
        });

        // In the morning the newest message is answered; the greeting does not count as an answer.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-17 07:02', 'Asia/Makassar'));
        $this->answer = 'Met pagi kak 😊 Tipe Tulip mulai 600 jutaan, lokasinya di Denpasar Utara.';
        $this->in(fn () => app(GenerateAiReply::class)($first->fresh(), true));
        $this->in(fn () => app(GenerateAiReply::class)($second->fresh(), true));

        $this->assertSame(['Terima kasih kak, kami balas besok pagi ya 🙏', $this->answer], $this->sent());
    }

    public function test_defer_mode_sends_nothing_at_night(): void
    {
        Queue::fake([GenerateAiReplyJob::class]);
        $this->settings(['quiet_from' => '21:00', 'quiet_until' => '07:00', 'quiet_mode' => 'defer']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-17 05:30', 'Asia/Makassar'));

        $message = $this->says('Pagi kak');
        $this->in(fn () => app(GenerateAiReply::class)($message));

        $this->assertSame([], $this->sent());
        Queue::assertPushed(GenerateAiReplyJob::class, fn (GenerateAiReplyJob $job) => $job->afterHours
            && CarbonImmutable::instance($job->delay)->setTimezone('Asia/Makassar')->format('Y-m-d H') === '2026-11-17 07');
    }

    public function test_quiet_hours_are_read_in_the_agency_time_zone(): void
    {
        $settings = AiSettings::of(new Tenant(['ai_settings' => ['quiet_from' => '21:00', 'quiet_until' => '07:00']]));

        $this->assertNull($settings->quietUntil(CarbonImmutable::parse('2026-11-16 20:59', 'Asia/Makassar'), 'Asia/Makassar'));
        $this->assertNotNull($settings->quietUntil(CarbonImmutable::parse('2026-11-16 21:00', 'Asia/Makassar'), 'Asia/Makassar'));
        // 21.30 WIB is 22.30 WITA: quiet for a Bali agency.
        $this->assertSame('2026-11-17 07:00', $settings->quietUntil(CarbonImmutable::parse('2026-11-16 21:30', 'Asia/Jakarta'), 'Asia/Makassar')->format('Y-m-d H:i'));
        $this->assertNull($settings->quietUntil(CarbonImmutable::parse('2026-11-17 07:00', 'Asia/Makassar'), 'Asia/Makassar'));
    }

    // ---- Settings and new numbers ------------------------------------------------------------------------------

    public function test_the_admin_sets_style_guard_and_timing(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageAiKnowledge::class)
            ->callAction('settings', [
                'ai_instructions' => 'Sapa dengan Kak.', 'ai_handoff_keywords' => 'nego', 'format' => 'whatsapp', 'max_lines' => 6,
                'guard' => true, 'forbidden_phrases' => "siap huni,\npasti disetujui", 'delay_min' => 5, 'delay_max' => 25, 'typing' => true,
                'quiet_from' => '21:00', 'quiet_until' => '07:00', 'quiet_mode' => 'greet', 'quiet_message' => 'Kami balas besok ya kak',
            ])
            ->assertHasNoActionErrors();

        $settings = AiSettings::of($this->tenant->fresh());
        $this->assertTrue($settings->whatsappFormat());
        $this->assertSame(6, $settings->maxLines());
        $this->assertSame(['siap huni', 'pasti disetujui'], $settings->forbiddenPhrases());
        $this->assertSame([5, 25], $settings->delay());
        $this->assertSame('Kami balas besok ya kak', $settings->quietMessage());

        Livewire::test(ManageAiKnowledge::class)->mountAction('settings')->callMountedAction([])->assertHasNoActionErrors();
        $this->tenant->forceFill(['ai_handoff_keywords' => 'nego, wna'])->save();
        Livewire::test(ManageAiKnowledge::class)->mountAction('settings')->callAction('propertyHandoff');
        $words = array_map('trim', explode(',', $this->tenant->fresh()->ai_handoff_keywords));
        $this->assertContains('notaris', $words);
        $this->assertSame(1, count(array_keys($words, 'wna')));
    }

    public function test_a_new_number_becomes_a_lead_and_the_ai_answers_it_with_its_tools(): void
    {
        $this->says('Halo, saya lihat iklan Green Tara', '6289900009999');

        $lead = $this->in(fn () => Lead::query()->where('phone', '+6289900009999')->first());
        $this->assertNotNull($lead);
        $this->assertNotNull($this->in(fn () => $lead->conversation));
        $tools = collect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['tools'])->pluck('name');
        $this->assertContains(PropertyTools::NEEDS, $tools);
        $this->assertSame(['Baik Kak 😊'], $this->sent());
    }
}
