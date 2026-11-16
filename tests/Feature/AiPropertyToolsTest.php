<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\MoveLeadToStage;
use App\Actions\RunFollowUps;
use App\Ai\PropertyTools;
use App\Enums\AiMode;
use App\Enums\PaymentMethod;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\FollowUpRules\Pages\ManageFollowUpRules;
use App\FollowUps\SurveyReminder;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\FollowUpLog;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaChannel;
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

/** "Now" in these tests is Monday 16 November 2026, 10.00 WITA (the agency is in Bali). */
class AiPropertyToolsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WaChannel $channel;

    private int $n = 0;

    /** @var list<array> the model's answers, in order */
    private array $ai = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Makassar'));
        $this->tenant = $this->makeTenant('griya-pelangi');
        // An agency made before Otomatisasi tahap (nothing stored): the tools alone move nothing but the survey.
        $this->tenant->forceFill(['name' => 'Rumahkudotcom', 'timezone' => 'Asia/Makassar', 'stage_automation' => null])->save();
        $this->channel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'Susan', 'phone' => '+6289900000001',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Survei', 'content' => 'Survei lokasi setiap hari pukul 09.00 sampai 16.00 di rumah contoh Kenanga.']));

        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? $this->text('Baik Kak 😊')),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
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

    private function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function tool(string $name, array $input = []): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_'.++$this->n, 'name' => $name, 'input' => $input]]];
    }

    private function says(string $text, ?WaChannel $channel = null, ?Tenant $tenant = null): WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel ?? $this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900001234'), 'text', $text, null, CarbonImmutable::now(), 'Rina',
        )), $tenant);
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->firstOrFail());
    }

    private function stageNamed(string $name): Stage
    {
        return $this->in(fn () => Stage::query()->where('name', $name)->firstOrFail());
    }

    private function sent(): array
    {
        return $this->in(fn () => WaMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
    }

    /** What the model was told back for its tool call. */
    private function toolResults(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))
            ->flatMap(fn ($pair) => collect($pair[0]['messages'])->flatMap(fn ($m) => is_array($m['content']) ? $m['content'] : []))
            ->where('type', 'tool_result')->pluck('content')->unique()->map(fn ($c) => json_decode(is_array($c) ? $c[0]['text'] : $c, true))->values()->all();
    }

    private function survey(string $date = '2026-11-21', string $time = '10:00', string $where = 'Rumah contoh Kenanga'): array
    {
        return $this->tool(PropertyTools::SURVEY, ['tanggal' => $date, 'jam' => $time, 'lokasi' => $where]);
    }

    public function test_needs_the_client_gave_are_saved_on_the_lead(): void
    {
        $this->says('Halo');
        $this->ai = [
            $this->tool(PropertyTools::NEEDS, ['tujuan' => 'hunian', 'domisili' => 'Denpasar', 'budget_min' => 1_000_000_000, 'budget_max' => 1_500_000_000, 'cara_bayar' => 'kpr', 'tipe' => 'Rumah']),
            $this->text('Siap Kak Rina, dicatat ya 😊 Mau dijadwalkan survei ke rumah contoh?'),
        ];

        $this->says('Saya Rina dari Denpasar, cari rumah buat tinggal, budget 1 sampai 1,5 M, rencana KPR');

        $lead = $this->lead();
        $this->assertSame(['hunian', 'Denpasar', 1_000_000_000, 1_500_000_000, PaymentMethod::Kpr, 'Rumah'],
            [$lead->purpose, $lead->domicile, (int) $lead->budget_min, (int) $lead->budget_max, $lead->payment_method, $lead->property_type]);
        $this->assertTrue($this->in(fn () => $lead->activities()->where('type', 'ai_needs')->exists()));
        $this->assertStringContainsString('dicatat ya', collect($this->sent())->last() ?? '');
    }

    public function test_a_saved_value_is_kept_unless_the_client_corrects_it(): void
    {
        $this->says('Halo');
        $this->in(fn () => $this->lead()->forceFill(['budget_max' => 2_000_000_000, 'domicile' => 'Tabanan'])->save());

        $this->ai = [$this->tool(PropertyTools::NEEDS, ['budget_max' => 1_000_000_000, 'domisili' => 'Denpasar']), $this->text('Baik Kak.')];
        $this->says('Budget saya sekitar 1 M, tinggal di Denpasar');

        $this->assertSame(2_000_000_000, (int) $this->lead()->budget_max);
        $this->assertSame('Tabanan', $this->lead()->domicile);
        $this->assertSame('tidak_berubah', $this->toolResults()[0]['status']);
        $this->assertArrayHasKey('tidak_ditimpa', $this->toolResults()[0]);

        $this->ai = [$this->tool(PropertyTools::NEEDS, ['budget_max' => 1_000_000_000, 'koreksi' => true]), $this->text('Siap Kak, budget dikoreksi.')];
        $this->says('Maaf ralat, budget maksimal 1 M saja');

        $this->assertSame(1_000_000_000, (int) $this->lead()->budget_max);
    }

    public function test_a_survey_the_client_agreed_to_is_scheduled_and_confirmed(): void
    {
        $this->says('Halo');
        $this->in(fn () => $this->lead()->forceFill(['name' => 'Rina'])->save());
        $this->ai = [$this->survey(), $this->text('Siap Kak Rina, survei dijadwalkan Sabtu, 21 November 2026 pukul 10.00 WITA di Rumah contoh Kenanga 😊')];

        $this->says('Sabtu jam 10 saya bisa survei ke rumah contoh');

        $lead = $this->lead();
        $this->assertSame('Jadwal survei', $lead->stage->name);
        $this->assertSame('2026-11-21 10:00', $lead->survey_at->timezone('Asia/Makassar')->format('Y-m-d H:i'));
        $this->assertSame('Rumah contoh Kenanga', $lead->survey_location);
        $this->assertSame('Konfirmasi H-1 sebelum survei', $lead->next_action);
        $this->assertTrue($this->in(fn () => $lead->activities()->where('type', 'ai_survey')->exists()));
        $this->assertSame('terjadwal', $this->toolResults()[0]['status']);
        $this->assertSame('Sabtu, 21 November 2026', $this->toolResults()[0]['hari_tanggal']);
        $this->assertSame('10.00 WITA', $this->toolResults()[0]['jam']);
    }

    public function test_a_survey_in_the_past_is_refused_and_nothing_is_written(): void
    {
        $this->says('Halo');
        $this->ai = [$this->survey('2026-11-16', '08:00'), $this->text('Mohon maaf Kak, jam itu sudah lewat. Mau jam berapa hari ini atau besok?')];

        $this->says('Hari ini jam 8 pagi bisa survei?');

        $lead = $this->lead();
        $this->assertNull($lead->survey_at);
        $this->assertNotSame('Jadwal survei', $lead->stage->name);
        $this->assertSame('ditolak', $this->toolResults()[0]['status']);
        $this->assertContains('Jadwal survei harus setelah sekarang.', $this->toolResults()[0]['masalah']);
        $this->assertFalse($this->in(fn () => $lead->activities()->whereIn('type', ['ai_survey', 'stage_changed'])->exists()));
    }

    public function test_a_survey_without_a_location_or_a_time_from_the_client_is_refused(): void
    {
        $this->says('Halo');

        // "Mau survei" alone names no day or time: the AI asks rather than picks one.
        $this->ai = [$this->survey(), $this->text('Mau survei hari apa dan jam berapa Kak?')];
        $this->says('oke mau survei');
        $this->assertSame('ditolak', $this->toolResults()[0]['status']);

        $this->ai = [$this->survey(where: ''), $this->text('Lokasi surveinya mau di mana Kak?')];
        $this->says('Sabtu jam 10 bisa');
        $this->assertContains('Isi lokasi survei.', $this->toolResults()[1]['masalah']);
        $this->assertNull($this->lead()->survey_at);
    }

    public function test_a_second_survey_changes_the_one_planned_instead_of_doubling_it(): void
    {
        $this->says('Halo');
        $this->ai = [$this->survey(), $this->text('Siap Kak, Sabtu 21 November pukul 10.00 di Rumah contoh Kenanga.')];
        $this->says('Sabtu jam 10 bisa survei');

        $this->ai = [$this->survey('2026-11-22', '14:00', 'Kantor pemasaran'), $this->text('Siap Kak, jadwal diubah ke Minggu 22 November pukul 14.00 di Kantor pemasaran.')];
        $this->says('Maaf, bisa diganti Minggu jam 2 siang di kantor pemasaran?');

        $lead = $this->lead();
        $this->assertSame('2026-11-22 14:00', $lead->survey_at->timezone('Asia/Makassar')->format('Y-m-d H:i'));
        $this->assertSame('Kantor pemasaran', $lead->survey_location);
        $this->assertSame('jadwal_diubah', $this->toolResults()[1]['status']);
        $this->assertSame(1, $this->in(fn () => $lead->activities()->where('type', 'stage_changed')->count()));
        $this->assertSame(1, $this->in(fn () => $lead->activities()->where('type', 'survey_changed')->count()));
        // H-1 of the new date, 10.00 WITA on 21 November is 09.00 WIB.
        $this->assertSame('2026-11-21 14:00', $lead->next_action_due_at->timezone('Asia/Makassar')->format('Y-m-d H:i'));
    }

    public function test_the_app_adds_the_confirmation_when_the_answer_leaves_it_out(): void
    {
        $this->says('Halo');
        $this->in(fn () => $this->lead()->forceFill(['name' => 'Rina'])->save());
        $this->ai = [$this->survey(), $this->text('Siap Kak, sudah dicatat ya.'), $this->survey(), $this->text('Siap Kak, sampai jumpa.')];

        $this->says('Sabtu jam 10 bisa survei');

        $this->assertStringContainsString('Konfirmasi jadwal survei Rina: Sabtu, 21 November 2026 pukul 10.00 WITA, di Rumah contoh Kenanga.', collect($this->sent())->last() ?? '');
    }

    public function test_draft_mode_writes_nothing_until_the_agent_sends(): void
    {
        $this->channel->forceFill(['ai_mode' => AiMode::Draft])->save();
        $this->says('Halo');
        $this->ai = [$this->survey(), $this->text('Siap Kak, Sabtu 21 November pukul 10.00 di Rumah contoh Kenanga.')];

        $message = $this->says('Sabtu jam 10 bisa survei');

        $this->assertNull($this->lead()->survey_at);
        $draft = $this->in(fn () => AiDraft::query()->where('message_id', $message->getKey())->first());
        $this->assertSame('survey', $draft->actions[0]['type']);
        $this->assertSame([], $this->sent());
    }

    public function test_a_lead_past_the_survey_is_not_taken_back(): void
    {
        $this->says('Halo');
        $this->in(fn () => app(MoveLeadToStage::class)($this->lead(), $this->stageNamed('Sudah survei'), null));
        $this->ai = [$this->survey(), $this->text('Baik Kak.')];

        $this->says('Sabtu jam 10 mau survei lagi');

        $this->assertSame('Sudah survei', $this->lead()->stage->name);
        $this->assertSame('ditolak', $this->toolResults()[0]['status']);
    }

    public function test_tools_are_only_for_property_agencies_and_stay_in_their_own(): void
    {
        $shop = $this->makeTenant('toko-contoh');
        $shop->forceFill(['industry' => 'retail'])->save();
        $shopChannel = $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6289900000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'ai_mode' => AiMode::Draft, 'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]), $shop);

        $this->says('Sabtu jam 10 bisa?', $shopChannel, $shop);

        $tools = collect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['tools'])->pluck('name');
        $this->assertNotContains(PropertyTools::NEEDS, $tools);
        $this->assertNotContains(PropertyTools::SURVEY, $tools);

        // The property agency's survey stage is its own: another agency's stages are never used.
        $other = $this->makeTenant('agensi-lain');
        $this->in(fn () => $this->assertNotNull(PropertyTools::surveyStage()), $other);
        $this->says('Halo');
        $this->ai = [$this->survey(), $this->text('Siap Kak, Sabtu 21 November pukul 10.00 di Rumah contoh Kenanga.')];
        $this->says('Sabtu jam 10 bisa survei');
        $this->assertSame($this->tenant->getKey(), $this->lead()->stage->tenant_id);
        $this->assertSame(0, $this->in(fn () => Lead::query()->count(), $other));
    }

    // ---- Survey reminder (H-1) ------------------------------------------------------------------------------

    private function scheduled(string $at = '2026-11-21 10:00'): Lead
    {
        $this->says('Halo');
        $lead = $this->lead();
        $this->in(fn () => app(MoveLeadToStage::class)($lead, $this->stageNamed('Jadwal survei'), null,
            ['survey_at' => CarbonImmutable::parse($at, 'Asia/Makassar')->timezone('Asia/Jakarta'), 'survey_location' => 'Rumah contoh Kenanga']));
        $this->in(fn () => SurveyReminder::rule()->update(['active' => true, 'send_time' => '09:00']));

        return $lead->refresh();
    }

    private function runFollowUps(): array
    {
        return app(RunFollowUps::class)($this->tenant);
    }

    public function test_the_client_is_reminded_the_day_before_their_survey(): void
    {
        $this->scheduled();
        $before = count($this->sent());

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 08:59', 'Asia/Makassar'));
        $this->assertSame(0, $this->runFollowUps()['sent']);

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 09:05', 'Asia/Makassar'));
        $this->assertSame(1, $this->runFollowUps()['sent']);
        $this->assertStringContainsString('Sabtu, 21 November 2026 pukul 10.00 WITA di Rumah contoh Kenanga', $this->sent()[$before]);

        // Once per survey date, even though the client wrote back.
        $this->says('Siap kak');
        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_a_new_date_gets_its_own_reminder(): void
    {
        $lead = $this->scheduled();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 09:05', 'Asia/Makassar'));
        $this->runFollowUps();

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 12:00', 'Asia/Makassar'));
        $this->in(fn () => app(MoveLeadToStage::class)->reschedule($lead->refresh(), ['survey_at' => CarbonImmutable::parse('2026-11-25 10:00', 'Asia/Makassar')->timezone('Asia/Jakarta'), 'survey_location' => 'Kantor'], null));

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-24 09:10', 'Asia/Makassar'));
        $this->assertSame(1, $this->runFollowUps()['sent']);
        $this->assertSame(2, $this->in(fn () => FollowUpLog::query()->where('status', 'sent')->count()));
    }

    public function test_no_reminder_when_the_lead_left_the_stage_or_the_date_was_set_late(): void
    {
        $lead = $this->scheduled();
        $this->in(fn () => app(MoveLeadToStage::class)($lead, $this->stageNamed('Sudah survei'), null));

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 09:05', 'Asia/Makassar'));
        $this->assertSame(0, $this->runFollowUps()['sent']);

        // Planned at 15.00 for the next morning: the reminder time (09.00 today) had passed, so the client is not told twice.
        $this->in(fn () => Lead::query()->delete());
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 15:00', 'Asia/Makassar'));
        $this->scheduled('2026-11-21 10:00');
        $this->assertSame(0, $this->runFollowUps()['sent']);
    }

    public function test_the_reminder_is_off_until_the_agency_turns_it_on(): void
    {
        $this->scheduled();
        $this->in(fn () => SurveyReminder::rule()->update(['active' => false]));

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 09:05', 'Asia/Makassar'));
        $this->assertSame(0, $this->runFollowUps()['sent']);
        // A new agency gets it switched off too, like the starter follow-ups.
        $this->assertFalse($this->in(fn () => SurveyReminder::rule()->active, $this->makeTenant('agensi-baru')));
    }

    public function test_the_admin_turns_the_reminder_on_in_follow_up(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageFollowUpRules::class)
            ->assertSee('Pengingat survei H-1')
            ->assertSee('Nonaktif')
            ->callAction('surveyReminder', ['send_time' => '08:30', 'body' => SurveyReminder::DEFAULT_BODY, 'active' => true])
            ->assertNotified('Pengingat survei disimpan');

        $rule = $this->in(fn () => SurveyReminder::current());
        $this->assertTrue($rule->active);
        $this->assertSame('08:30', substr($rule->send_time, 0, 5));
    }
}
