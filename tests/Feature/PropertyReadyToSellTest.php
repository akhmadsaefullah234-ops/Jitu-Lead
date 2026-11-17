<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\AiReadiness;
use App\Ai\AiSettings;
use App\Ai\AutoGate;
use App\Ai\PropertyTools;
use App\Enums\AiMode;
use App\Filament\Pages\AiSurveyReport;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaLabelEvent;
use App\Reports\AiSurveyData;
use App\Support\CurrentTenant;
use App\Support\Period;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Database\Seeders\AgensiDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A NEW property agency (the "Agensi Demo" seeder) that was only given its knowledge runs by itself: ready for
 * "Naikkan ke Auto" with Jitu's AI settings as they come, moving the lead through its stages and tasks from the chat,
 * sending the Meta events and the WhatsApp labels, and showing it all on "AI dan Survei". Every client here is made
 * up; numbers start 0899000. "Now" is Monday 16 November 2026, 10.00 WIB.
 */
class PropertyReadyToSellTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WaChannel $channel;

    private int $n = 0;

    /** @var list<array> the model's answers, in order */
    private array $ai = [];

    private bool $first = true;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Jakarta'));
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gateway.contoh.test' ? ['93.184.216.34'] : []);
        $labels = [['id' => '1', 'name' => 'Prospek', 'color' => 1], ['id' => '2', 'name' => 'Order baru', 'color' => 2], ['id' => '3', 'name' => 'Lunas', 'color' => 3]];
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->ai) ?? $this->text('Baik Kak 😊')),
            'gateway.contoh.test/sessions/*/chat-labels' => fn () => Http::response(['label' => ['id' => '1', 'name' => 'x'], 'already' => false]),
            'gateway.contoh.test/sessions/*/labels/resync' => fn () => Http::response(['labels' => $labels, 'resynced' => true]),
            'gateway.contoh.test/sessions/*/labels' => fn () => Http::response(['labels' => $labels]),
            'gateway.contoh.test/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
            '*' => Http::response(['ok' => true]),
        ]);

        $this->seed(AgensiDemoSeeder::class);
        $this->tenant = Tenant::query()->where('slug', 'agensi-demo')->firstOrFail();
        $this->channel = $this->in(fn () => WaChannel::query()->firstOrFail());
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

    /** A message from the made-up client a minute after the last one, like a person types; the first comes from a Click-to-WhatsApp ad. */
    private function says(string $text): void
    {
        $this->travel(1)->minutes();
        $ad = $this->first;
        $this->first = false;
        $this->in(fn () => app(HandleInboundWhatsApp::class)->message($this->channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900007771'), 'text', $text, null, CarbonImmutable::now(), 'Klien Baru',
            $ad ? ['source_id' => '120210000000001', 'headline' => 'Cluster Contoh Asri', 'ctwa_clid' => 'clid-demo'] : null,
        )));
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->with('stage')->where('phone', 'like', '%89900007771')->firstOrFail());
    }

    /** @return list<string> */
    private function metaEvents(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/events'))->map(fn ($p) => $p[0]['data'][0]['event_name'])->values()->all();
    }

    private function summary(?User $viewer = null): array
    {
        Cache::flush();
        $this->actingAs($viewer ?? $this->adminOf($this->tenant));

        return $this->in(fn () => app(AiSurveyData::class)->summary(Period::make('7d', null, null, default: '7d')));
    }

    // ---- The new demo agency runs by itself ---------------------------------------------------------------------

    public function test_the_new_demo_agency_keeps_jitus_ai_settings_and_is_ready_for_auto(): void
    {
        $this->assertSame('properti', $this->tenant->industry()->key);
        $this->assertSame(AiSettings::NEW_PROPERTY, $this->tenant->ai_settings, 'nothing in the AI settings was touched: only the knowledge was filled');
        $this->assertTrue(AutoGate::locked($this->tenant));

        $check = $this->in(fn () => AiReadiness::check());
        $this->assertTrue($check['passed'], implode(', ', $this->in(fn () => AiReadiness::missing())));
        $this->assertSame([], $this->in(fn () => AutoGate::missing()));
    }

    public function test_the_demo_agency_moves_the_lead_sets_its_tasks_and_sends_events_and_labels_by_itself(): void
    {
        $before = $this->summary();

        // The owner connects Meta and presses "Naikkan ke Auto"; nothing else is set.
        $this->in(fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890', 'credentials' => ['meta_capi_token' => 'demo-token']]));
        $this->in(fn () => AutoGate::open($this->tenant->fresh()));
        $this->assertSame(AiMode::Auto, $this->channel->fresh()->ai_mode);

        $this->says('Halo kak, Cluster Contoh Asri tipe 36 masih ada?');
        $this->assertSame('Lead baru', $this->lead()->stage->name);
        $this->assertNull($this->lead()->next_action, 'the AI answered, so the first task is done');

        $this->ai = [$this->tool(PropertyTools::NEEDS, ['tujuan' => 'hunian']), $this->text('Siap Kak, untuk tinggal ya 😊 Domisili di mana Kak?')];
        $this->says('Untuk tinggal kak');
        $this->assertSame('Dihubungi', $this->lead()->stage->name);

        $this->ai = [$this->tool(PropertyTools::NEEDS, ['domisili' => 'Kota Contoh Selatan']), $this->text('Siap Kak 😊 Mau survei ke rumah contoh?')];
        $this->says('Saya di Kota Contoh Selatan');
        $this->assertSame('Terkualifikasi', $this->lead()->stage->name);

        $this->ai = [$this->tool(PropertyTools::SURVEY, ['tanggal' => '2026-11-21', 'jam' => '10:00', 'lokasi' => 'Rumah contoh Cluster Contoh Asri']),
            $this->text('Siap Kak, survei dijadwalkan Sabtu, 21 November 2026 pukul 10.00 WIB di Rumah contoh Cluster Contoh Asri 😊')];
        $this->says('Sabtu jam 10 saya bisa survei');

        $lead = $this->lead();
        $this->assertSame('Jadwal survei', $lead->stage->name);
        $this->assertSame('Konfirmasi H-1 sebelum survei', $lead->next_action, 'the survey stage brings its own task');
        $this->assertSame(['Lead', 'Contact', 'QualifiedLead', 'Schedule'], $this->metaEvents());
        $this->assertSame(['Prospek'], $this->in(fn () => WaLabelEvent::query()->where('lead_id', $lead->getKey())->where('status', 'sent')->pluck('label')->all()));

        $data = $this->summary();
        $this->assertSame([$before['conversations'] + 1, $before['replied'] + 1], [$data['conversations'], $data['replied']]);
        $this->assertSame([$before['surveys'] + 1, $before['surveys_auto'] + 1], [$data['surveys'], $data['surveys_auto']]);
        $stage = collect($data['stages'])->firstWhere('name', 'Jadwal survei');
        $this->assertSame(['Schedule', 2], [$stage['event'], $stage['event_sent']], 'the demo\'s one and this one');
    }

    // ---- "AI dan Survei" -----------------------------------------------------------------------------------------

    public function test_the_dashboard_counts_chats_replies_surveys_handovers_events_and_labels(): void
    {
        $data = $this->summary();

        $this->assertSame([3, 3, 2, 66.7], [$data['conversations'], $data['answered'], $data['replied'], $data['replied_rate']]);
        $this->assertSame([1, 1, 0], [$data['surveys'], $data['surveys_auto'], $data['surveys_suggested']], 'the other demo surveys were a week or more ago');
        $this->assertSame(30.0, $data['hours_to_survey']);
        $this->assertSame(['Klien minta bicara dengan agen' => 1, 'Klien siap booking unit' => 1], $data['handoff_reasons']);

        $rows = collect($data['stages'])->keyBy('name');
        $this->assertSame(['QualifiedLead', 2, 'Prospek', 3], [$rows['Terkualifikasi']['event'], $rows['Terkualifikasi']['event_sent'], $rows['Terkualifikasi']['label'], $rows['Terkualifikasi']['label_sent']]);
        $this->assertTrue($rows['Jadwal survei']['label_shared'], 'Prospek goes on once, so it is counted on its first stage only');
        $this->assertNull($rows['Jadwal survei']['label_sent']);
        $this->assertNull($rows['Negosiasi']['event']);
        $this->assertArrayNotHasKey('Gugur', $rows->all());
    }

    public function test_handover_reasons_are_grouped_without_their_prefix_or_detail(): void
    {
        $this->assertSame('Klien minta bicara dengan agen', AiSurveyData::reason('AI menyerahkan chat ke agen: Klien minta bicara dengan agen'));
        $this->assertSame('Harga belum tercatat', AiSurveyData::reason('AI menyerahkan chat ke agen: Harga belum tercatat: tipe 60/120'));
        $this->assertSame('AI gagal menjawab (batas waktu)', AiSurveyData::reason('AI gagal menjawab: batas waktu'));
        $this->assertSame('Tanpa alasan', AiSurveyData::reason('AI menyerahkan chat ke agen:'));
    }

    public function test_the_page_is_for_property_teams_and_shows_on_a_phone(): void
    {
        $owner = User::factory()->create();
        $shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', $owner, 'retail');
        $admin = $this->adminOf($this->tenant);
        $this->actingInTenant($admin, $this->tenant);
        $url = AiSurveyReport::getUrl(tenant: $this->tenant);

        $this->get($url)->assertOk()
            ->assertSee('Percakapan baru')->assertSee('Membalas pesan kedua')->assertSee('Survei dijadwalkan AI')
            ->assertSee('Rata-rata sampai survei')->assertSee('Serah-terima ke agen')->assertSee('Klien siap booking unit')
            ->assertSee('QualifiedLead')->assertSee('Prospek')->assertSee('dihitung di atas')
            ->assertSee('@media (max-width: 639px)', false)->assertSee('data-label="Event Meta"', false);

        // A shop has no such page, and neither has an agent (the numbers are the team's).
        $this->actingInTenant($owner, $shop);
        $this->assertFalse(AiSurveyReport::canAccess());

        $agent = $this->in(fn () => $this->tenant->users()->wherePivot('role', 'agent')->firstOrFail());
        $this->actingInTenant($agent, $this->tenant);
        $this->assertFalse(AiSurveyReport::canAccess());
    }
}
