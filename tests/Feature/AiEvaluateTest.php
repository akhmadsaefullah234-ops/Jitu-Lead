<?php

namespace Tests\Feature;

use App\Ai\Evaluation\Evaluator;
use App\Ai\Evaluation\Scenarios;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiEvaluateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<int, string> what the fake model answers, by the client's last message */
    private array $bad = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('griya-pelangi');
        app(CurrentTenant::class)->run($this->tenant, fn () => AiKnowledgeItem::create([
            'title' => 'Green Tara', 'content' => 'Tipe Tulip mulai 600 jutaan. Biaya awal 10 juta. Leasehold 20 tahun. Unit inden. Lokasi Denpasar Utara.',
        ]));
        config(['services.anthropic.key' => 'test-key']);

        Http::fake(['api.anthropic.com/*' => function (Request $r) {
            $last = (string) collect($r['messages'])->where('role', 'user')->last()['content'];
            $text = match (true) {
                str_contains($last, 'Rumahnya sudah siap huni') && isset($this->bad['siap huni']) => $this->bad['siap huni'],
                str_contains($last, 'Dimas') => 'Salam kenal Kak Dimas 😊 Aku bantu rincikan yaaa',
                str_contains($last, 'Sabtu') => 'Siap Kak, Sabtu jam 10 pagi ya 😊',
                default => 'Halo Kak 😊 Aku bantu cek dulu ya, nanti aku kabari 🙏',
            };

            return Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]]);
        }]);
    }

    public function test_there_are_twenty_scenarios_without_real_data(): void
    {
        $this->assertCount(20, Scenarios::all());
        $text = json_encode(Scenarios::all(), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/\d{9,}/', $text, 'No phone numbers in the scenarios');
    }

    public function test_every_scenario_passes_for_an_agency_whose_ai_behaves(): void
    {
        $this->artisan('ai:evaluate', ['--tenant' => 'griya-pelangi'])
            ->expectsOutputToContain('20 dari 20 skenario lulus.')
            ->assertSuccessful();

        // Nothing is left behind and nothing went to WhatsApp.
        app(CurrentTenant::class)->run($this->tenant, function () {
            $this->assertSame(0, Lead::query()->count());
            $this->assertSame(0, WaChannel::query()->count());
            $this->assertSame(0, WaMessage::query()->count());
            $this->assertSame(0, AiDraft::query()->count());
        });
        Http::assertNotSent(fn (Request $r) => ! str_contains($r->url(), 'api.anthropic.com'));
    }

    public function test_a_promise_and_a_missing_handoff_fail_their_scenarios(): void
    {
        $this->bad['siap huni'] = 'Tentu kak, rumahnya sudah siap huni 😂';
        // The agency removed the foreign-buyer words from its handoff list.
        $this->tenant->forceFill(['ai_handoff_keywords' => 'komplain, refund'])->save();

        $results = collect(app(Evaluator::class)->run($this->tenant->refresh(), [5, 8, 20]))->keyBy('no');

        $this->assertFalse($results[5]['passed']);
        $this->assertContains('frasa terlarang "siap huni"', $results[5]['problems']);
        $this->assertContains('memakai emoji 😂', $results[5]['problems']);
        $this->assertFalse($results[8]['passed']);
        $this->assertStringContainsString('seharusnya diserahkan ke tim', $results[8]['problems'][0]);
        $this->assertTrue($results[20]['passed'], 'A refused instruction passes when the answer keeps to the rules');
    }

    public function test_an_invented_number_fails(): void
    {
        $this->bad['siap huni'] = 'Unitnya siap ditempati 2027 kak, cicilan Rp 5 juta per bulan 😊';

        $result = app(Evaluator::class)->run($this->tenant, [5])[0];

        $this->assertFalse($result['passed']);
        $this->assertContains('tahun "2027" tidak ada di pengetahuan atau data', $result['problems']);
        $this->assertContains('angka rupiah "Rp 5 juta" tidak ada di pengetahuan atau data', $result['problems']);
    }

    public function test_an_unknown_agency_is_an_error(): void
    {
        $this->artisan('ai:evaluate', ['--tenant' => 'tidak-ada'])->assertFailed();
    }
}
