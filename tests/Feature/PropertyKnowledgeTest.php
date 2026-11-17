<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Ai\AiReadiness;
use App\Ai\AiSettings;
use App\Ai\DocumentReader;
use App\Ai\Evaluation\AiHealth;
use App\Ai\Evaluation\Scenarios;
use App\Ai\Facts\FactExtractor;
use App\Ai\Facts\FactFormat;
use App\Ai\Facts\Facts;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\AiHealthPage;
use App\Filament\Pages\FactReview;
use App\Filament\Pages\PropertySetup;
use App\Jobs\RunAiCheck;
use App\Mail\AiHealthDropped;
use App\Models\AiEvaluation;
use App\Models\AiFact;
use App\Models\AiKnowledgeItem;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** PR 5: structured property knowledge ("Isi properti"), document review, conflicts, AI readiness and AI health. */
class PropertyKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** What the fake model fills in when asked to read a document. */
    private array $extracted = [];

    private const WIZARD = [
        'project' => [
            'project' => 'Perumahan Contoh Asri', 'developer' => 'PT Contoh Properti', 'location' => 'Jl. Contoh Raya 1, Denpasar',
            'legal' => 'SHGB', 'handover' => 'Sekitar 18 bulan setelah akad', 'payment_methods' => 'Cash keras, KPR',
            'booking_fee' => '5 juta', 'down_payment' => '10%', 'tenor' => 'KPR sampai 20 tahun',
            'survey_hours' => 'Setiap hari 09.00 sampai 17.00', 'survey_location' => 'Kantor pemasaran', 'contact' => 'Kantor pemasaran 0899000111',
        ],
        'types' => [
            ['name' => 'Melati', 'price' => '642850000', 'building_area' => '38', 'land_area' => '72', 'bedrooms' => '2', 'bathrooms' => '1', 'availability' => 'Inden', 'bonus' => 'Kanopi'],
            ['name' => 'Kenanga', 'price' => '1,25 M', 'building_area' => '60', 'land_area' => '120', 'availability' => 'Tersedia'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-17 14:00', 'Asia/Jakarta'));
        $this->tenant = $this->makeTenant('agensi-uji');
        $this->tenant->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $this->actingAs($this->tenant->users()->first());
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => fn (Request $r) => Http::response(['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => FactExtractor::TOOL, 'input' => $this->extracted],
        ]])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->tenant, $fn);
    }

    private function knowledge(): array
    {
        return $this->in(fn () => AiKnowledgeItem::query()->where('source', Facts::KNOWLEDGE_SOURCE)->orderBy('id')->pluck('content', 'title')->all());
    }

    // ---- Penulisan seragam ----------------------------------------------------------------------------------

    public function test_money_and_areas_are_written_one_way(): void
    {
        $this->assertSame('Rp642.850.000 (sekitar Rp642,85 juta)', FactFormat::money(642850000));
        $this->assertSame('Rp500.000.000 (Rp500 juta)', FactFormat::money(500000000));
        $this->assertSame('Rp1.250.000.000 (sekitar Rp1,25 miliar)', FactFormat::money(1250000000));
        $this->assertSame(642850000, FactFormat::rupiah('642,85 juta'));
        $this->assertSame(642850000, FactFormat::rupiah('Rp 642.850.000'));
        $this->assertSame(1250000000, FactFormat::rupiah('1,25 M'));
        $this->assertSame('38', FactFormat::clean('land_area', '38 m²'));
        $this->assertSame('38 m2', FactFormat::show('land_area', '38'));
    }

    // ---- Wizard -----------------------------------------------------------------------------------------------

    public function test_the_wizard_fills_the_catalogue_and_the_standard_knowledge(): void
    {
        $this->in(fn () => Livewire::test(PropertySetup::class)->set('data', self::WIZARD)->call('save')->assertNotified('Data properti disimpan'));

        $knowledge = $this->knowledge();
        $this->assertStringContainsString('Harga: Rp642.850.000 (sekitar Rp642,85 juta).', $knowledge['Tipe Melati']);
        $this->assertStringContainsString('Luas bangunan: 38 m2.', $knowledge['Tipe Melati']);
        $this->assertStringContainsString('Luas tanah: 72 m2.', $knowledge['Tipe Melati']);
        $this->assertStringContainsString('Booking fee: Rp5.000.000 (Rp5 juta).', $knowledge['Cara bayar']);
        $this->assertStringContainsString('Jam survei: Setiap hari 09.00 sampai 17.00.', $knowledge['Jam dan lokasi survei']);
        $this->assertArrayHasKey('Proyek Perumahan Contoh Asri', $knowledge);

        $melati = $this->in(fn () => Property::query()->where('property_type', 'Melati')->first());
        $this->assertSame('Perumahan Contoh Asri - Tipe Melati', $melati->name);
        $this->assertSame(642850000, (int) $melati->price_from);
        $this->assertSame('38 m2', $melati->details['Luas bangunan']);
        $this->assertSame($melati->getKey(), $this->in(fn () => AiKnowledgeItem::query()->where('title', 'Tipe Melati')->value('property_id')), 'Each type entry is linked to its property');

        // Saved again with a new price: no duplicates, the same property is updated.
        $this->in(function () {
            $form = Facts::form();
            $form['types'][0]['price'] = '650000000';
            Facts::saveWizard($form);
        });
        $this->assertSame(2, $this->in(fn () => Property::query()->count()));
        $this->assertSame(1, $this->in(fn () => AiKnowledgeItem::query()->where('title', 'Tipe Melati')->count()));
        $this->assertStringContainsString('Rp650.000.000', $this->knowledge()['Tipe Melati']);
        $this->assertSame(650000000, (int) $melati->refresh()->price_from);
    }

    public function test_the_wizard_shows_what_was_saved(): void
    {
        $this->in(fn () => Facts::saveWizard(self::WIZARD));

        $page = $this->in(fn () => Livewire::test(PropertySetup::class)->assertSet('data.project.project', 'Perumahan Contoh Asri'));
        $first = array_values($page->get('data.types'))[0];
        $this->assertSame(['Melati', '642850000', '38'], [$first['name'], $first['price'], $first['building_area']]);
    }

    public function test_a_shop_does_not_see_the_property_pages(): void
    {
        $shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
        $this->actingAs($shop->users()->first());

        $this->in(fn () => $this->assertFalse(PropertySetup::canAccess()), $shop);
        $this->in(fn () => $this->assertFalse(AiHealthPage::canAccess()), $shop);
    }

    // ---- Dokumen dan tinjauan ---------------------------------------------------------------------------------

    public function test_a_document_is_read_into_facts_that_wait_for_the_owner(): void
    {
        $this->in(fn () => Facts::saveWizard(self::WIZARD));
        $this->extracted = ['project' => ['tenor' => 'KPR sampai 20 tahun', 'rent_estimate' => 'Rp4 juta per bulan'],
            'types' => [['name' => 'Tipe Melati', 'land_area' => '75', 'building_area' => '38']]];
        $path = $this->file('daftar-harga.txt', 'Tipe Melati LT 75 LB 38. Sewa sekitar 4 juta.');

        $result = $this->in(fn () => app(FactExtractor::class)($path, 'daftar-harga.txt'));

        $this->assertSame(['added' => 2, 'same' => 2], $result, 'Values the agency already has are not asked again');
        $pending = $this->in(fn () => AiFact::query()->where('status', AiFact::PENDING)->get());
        $this->assertSame(['rent_estimate', 'land_area'], $pending->pluck('key')->all());
        $this->assertSame(['daftar-harga.txt'], $pending->pluck('source_name')->unique()->values()->all());
        $this->assertArrayNotHasKey('Perkiraan sewa', $this->knowledge(), 'Nothing reaches the AI before the owner approves it');
        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0];
        $this->assertSame(['type' => 'tool', 'name' => FactExtractor::TOOL], $sent['tool_choice']);

        // The land area disagrees with the wizard: shown as a conflict and left out of the AI's knowledge.
        $conflicts = $this->in(fn () => Facts::conflicts());
        $this->assertCount(1, $conflicts);
        $this->assertSame('land_area', $conflicts[0]['key']);

        $this->in(function () use ($pending) {
            Livewire::test(FactReview::class)
                ->assertCanSeeTableRecords($pending)
                ->callTableAction('approve', $pending->firstWhere('key', 'rent_estimate'))
                ->callTableAction('choose', $pending->firstWhere('key', 'land_area'));
        });

        $knowledge = $this->knowledge();
        $this->assertStringContainsString('Perkiraan sewa: Rp4 juta per bulan. Ini perkiraan, bukan janji', $knowledge['Perkiraan sewa']);
        $this->assertStringContainsString('Luas tanah: 75 m2.', $knowledge['Tipe Melati']);
        $this->assertSame([], $this->in(fn () => Facts::conflicts()));
        $this->assertSame('daftar-harga.txt', $this->in(fn () => AiFact::query()->where('status', AiFact::APPROVED)->where('key', 'land_area')->where('subject', 'Tipe Melati')->value('source_name')));
    }

    public function test_an_unresolved_conflict_keeps_the_fact_out_of_the_knowledge(): void
    {
        $this->in(function () {
            Facts::saveWizard(self::WIZARD);
            AiFact::query()->create(['subject' => 'Melati', 'key' => 'land_area', 'value' => '75', 'source' => AiFact::DOCUMENT, 'source_name' => 'brosur.pdf', 'status' => AiFact::PENDING]);
            Facts::rebuild();
        });

        $this->assertStringNotContainsString('Luas tanah', $this->knowledge()['Tipe Melati']);
        $this->assertStringContainsString('Luas bangunan: 38 m2.', $this->knowledge()['Tipe Melati']);
    }

    public function test_an_excel_price_list_and_a_photo_can_be_read(): void
    {
        $path = $this->file('harga.xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Tipe</t></si><si><t>Harga</t></si><si><t>Melati</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c t="s"><v>0</v></c><c t="s"><v>1</v></c></row><row><c t="s"><v>2</v></c><c><v>642850000</v></c></row></sheetData></worksheet>');
        $zip->close();

        $this->assertSame("Tipe | Harga\nMelati | 642850000", app(DocumentReader::class)->read($path, 'xlsx'));

        $this->extracted = ['types' => [['name' => 'Melati', 'price' => '642850000']]];
        $photo = UploadedFile::fake()->image('brosur.jpg');
        $this->in(fn () => app(FactExtractor::class)($photo->getRealPath(), 'brosur.jpg'));
        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0];
        $this->assertSame('image', $sent['messages'][0]['content'][0]['type']);
        $this->assertSame('642850000', $this->in(fn () => AiFact::query()->where('key', 'price')->value('value')));
    }

    // ---- Kesiapan AI ------------------------------------------------------------------------------------------

    public function test_the_readiness_score_lists_what_is_missing_and_passes_once_complete(): void
    {
        $empty = $this->in(fn () => AiReadiness::check());
        $this->assertFalse($empty['passed']);
        $this->assertLessThan(20, $empty['score']);

        $this->in(fn () => Facts::saveWizard(self::WIZARD));
        $filled = $this->in(fn () => AiReadiness::check());
        $this->assertFalse($filled['passed'], 'Uji AI has not been run yet');
        $this->assertSame(['test'], collect($filled['items'])->reject(fn ($i) => $i['ok'])->pluck('key')->values()->all());

        $this->in(fn () => AiEvaluation::query()->create(['trigger' => 'quick', 'passed' => 9, 'total' => 10, 'results' => []]));
        $this->assertSame(100, $this->in(fn () => AiReadiness::check())['score']);
        $this->assertTrue($this->in(fn () => AiReadiness::check())['passed']);

        // A conflict is required to be decided.
        $this->in(fn () => AiFact::query()->create(['subject' => 'Melati', 'key' => 'price', 'value' => '700000000', 'source' => AiFact::DOCUMENT, 'status' => AiFact::PENDING]));
        $this->assertFalse($this->in(fn () => AiReadiness::check())['passed']);
    }

    // ---- Evaluasi per agensi ----------------------------------------------------------------------------------

    public function test_scenarios_are_written_from_the_agencys_own_facts(): void
    {
        $this->in(fn () => Facts::saveWizard(self::WIZARD));

        $own = Scenarios::fromFacts($this->tenant);
        $titles = array_column($own, 'title');
        $this->assertContains('Harga tipe Melati (data agensi)', $titles);
        $this->assertContains('Luas tipe Kenanga (data agensi)', $titles);
        $this->assertContains('Jam survei (data agensi)', $titles);
        $this->assertContains('Cara bayar (data agensi)', $titles);
        $this->assertContains('Lokasi proyek (data agensi)', $titles);

        $price = array_key_first($own[array_search('Harga tipe Melati (data agensi)', $titles, true)]['must']);
        foreach (['Rp642.850.000 kak', 'Mulai 642,85 juta kak', 'sekitar 642 jutaan'] as $ok) {
            $this->assertMatchesRegularExpression($price, $ok);
        }
        $this->assertDoesNotMatchRegularExpression($price, 'Mulai 600 jutaan kak');
        $big = array_key_first($own[array_search('Harga tipe Kenanga (data agensi)', $titles, true)]['must']);
        $this->assertMatchesRegularExpression($big, 'Harganya 1,25 M kak');

        $this->assertCount(20 + count($own), Scenarios::for($this->tenant));
        $quick = Scenarios::quick($this->tenant);
        $this->assertCount(10, $quick);
        $this->assertSame('Harga tipe Melati (data agensi)', $quick[0]['title']);
        $this->assertContains('Klien warga negara asing', array_column($quick, 'title'));
    }

    public function test_a_recorded_run_is_kept_and_a_drop_emails_the_admins(): void
    {
        Mail::fake();
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Halo Kak 😊 Aku bantu cek dulu ya']]])]);
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Umum', 'content' => 'Lokasi Denpasar.']));
        $this->in(fn () => AiEvaluation::query()->create(['trigger' => 'manual', 'passed' => 20, 'total' => 20, 'results' => []]));

        $this->artisan('ai:evaluate', ['--tenant' => 'agensi-uji', '--quick' => true, '--record' => 'quick']);

        $run = $this->in(fn () => AiEvaluation::query()->latest('id')->first());
        $this->assertSame('quick', $run->trigger);
        $this->assertSame(10, $run->total);
        $this->assertLessThan(100, $run->score());
        $this->assertSame($this->in(fn () => AiHealth::version()), $run->knowledge_version);
        Mail::assertSent(AiHealthDropped::class, fn (AiHealthDropped $m) => $m->hasTo($this->tenant->users()->first()->email));
    }

    public function test_ai_health_runs_agencies_whose_knowledge_changed_and_every_night(): void
    {
        Process::fake();
        $this->tenant->forceFill(['ai_settings' => ['auto_eval' => true]])->save();
        $this->in(fn () => WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'Nomor', 'phone' => '+6289900000021',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Draft, 'credentials' => []]));
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Umum', 'content' => 'Lokasi Denpasar.']));

        $this->assertNull(AiHealth::due($this->tenant, CarbonImmutable::now()), 'A change settles for 10 minutes first');
        $this->assertSame('knowledge', AiHealth::due($this->tenant, CarbonImmutable::now()->addMinutes(11)));

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('ai:health')->assertSuccessful();
        Process::assertRan(fn ($process) => in_array('--tenant=agensi-uji', $process->command, true) && in_array('--record=knowledge', $process->command, true));

        // Evaluated for this knowledge: nothing until the night, then once.
        $this->in(fn () => AiEvaluation::query()->create(['trigger' => 'knowledge', 'passed' => 20, 'total' => 20, 'results' => [], 'knowledge_version' => AiHealth::version()]));
        $this->assertNull(AiHealth::due($this->tenant, CarbonImmutable::now()));
        $this->assertSame('nightly', AiHealth::due($this->tenant, CarbonImmutable::parse('2026-11-18 02:00', 'Asia/Jakarta')));

        // Off for an agency without "Uji otomatis" (every agency made before this) and for shops.
        $this->tenant->forceFill(['ai_settings' => null])->save();
        $this->assertNull(AiHealth::due($this->tenant->refresh(), CarbonImmutable::parse('2026-11-18 02:00', 'Asia/Jakarta')));
    }

    public function test_new_property_agencies_have_automatic_tests_and_existing_ones_do_not(): void
    {
        $new = app(ProvisionTenant::class)('Agensi Baru', 'agensi-baru', User::factory()->create(), 'properti');

        $this->assertTrue(AiSettings::of($new)->autoEval());
        $this->assertFalse(AiSettings::of($this->tenant)->autoEval());
    }

    public function test_uji_ai_saya_runs_ten_questions_in_the_background(): void
    {
        Queue::fake();
        $this->in(fn () => Livewire::test(AiHealthPage::class)
            ->assertSee('Kesiapan AI')->assertSee('Belum pernah diuji')
            ->callAction('check')->assertNotified('Uji AI berjalan'));

        Queue::assertPushed(RunAiCheck::class, fn (RunAiCheck $job) => $job->tenantId === $this->tenant->getKey());
        $this->assertTrue(Cache::has(RunAiCheck::runningKey($this->tenant->getKey())));

        Process::fake();
        (new RunAiCheck($this->tenant->getKey()))->handle();
        Process::assertRan(fn ($process) => in_array('--quick', $process->command, true) && in_array('--record=quick', $process->command, true));
        $this->assertFalse(Cache::has(RunAiCheck::runningKey($this->tenant->getKey())));
    }

    public function test_the_health_page_shows_the_last_run_and_switches_automatic_tests(): void
    {
        $this->in(fn () => AiEvaluation::query()->create(['trigger' => 'quick', 'passed' => 1, 'total' => 2, 'results' => [
            ['no' => 1, 'title' => 'Harga tipe Melati (data agensi)', 'passed' => false, 'outcome' => 'menjawab', 'reply' => 'Mulai 600 jutaan', 'problems' => ['tidak menyebut harga']],
            ['no' => 2, 'title' => 'Sapaan pertama', 'passed' => true, 'outcome' => 'menjawab', 'reply' => 'Halo Kak', 'problems' => []],
        ]]));

        $this->in(fn () => Livewire::test(AiHealthPage::class)
            ->assertSee('1 dari 2 lulus (50%)')->assertSee('tidak menyebut harga')
            ->callAction('autoEval')->assertNotified('Uji otomatis menyala'));

        $this->assertTrue(AiSettings::of($this->tenant->refresh())->autoEval());
    }

    private function file(string $name, string $content = ''): string
    {
        $dir = sys_get_temp_dir().'/jitu-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/'.$name, $content);

        return $dir.'/'.$name;
    }
}
