<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\AiReadiness;
use App\Ai\AiSettings;
use App\Ai\Evaluation\Scenarios;
use App\Ai\Facts\FactExtractor;
use App\Ai\Facts\Facts;
use App\Ai\Facts\PaymentDefaults;
use App\Ai\Facts\PaymentModel;
use App\Ai\PropertyGuard;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\PropertySetup;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Database\Seeders\AgensiDemoPembayaranSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 7: how an agency is paid is data (the payment model), not an assumption. The AI asks only about the ways offered,
 * declines the rest honestly, and an answer is held only when it contradicts the model; Jitu's wording follows it for
 * new agencies. Every agency, client and number is made up; phone numbers start 0899000.
 */
class PaymentModelTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = [
        'project' => 'Perumahan Contoh Asri', 'location' => 'Jl. Contoh Raya 1, Kota Contoh', 'legal' => 'SHM', 'handover' => '12 bulan',
        'survey_hours' => 'Setiap hari 09.00 sampai 16.00', 'contact' => 'Kantor pemasaran',
    ];

    private const TYPES = [['name' => 'Melati', 'price' => '500000000', 'building_area' => '36', 'land_area' => '72', 'availability' => 'Tersedia']];

    private int $n = 0;

    private string $answer = 'Baik Kak 😊';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->answer]]]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $fn);
    }

    private function newAgency(string $slug = 'agensi-baru'): Tenant
    {
        return app(ProvisionTenant::class)('Agensi '.$slug, $slug, User::factory()->create(), 'properti');
    }

    /** @param  array<string, mixed>  $payment */
    private function fill(Tenant $tenant, array $payment): void
    {
        $this->in($tenant, fn () => Facts::saveWizard(['project' => self::PROJECT + $payment, 'types' => self::TYPES]));
    }

    private function model(Tenant $tenant): PaymentModel
    {
        return $this->in($tenant, fn () => PaymentModel::current());
    }

    private const CASH_ONLY = ['pay_options' => ['installment'], 'installment_tenor' => '24', 'installment_interest' => 'tidak', 'dp_model' => 'standard', 'dp_amount' => '30%'];

    private const KPR_LIGHT = ['pay_options' => ['cash', 'kpr'], 'dp_model' => 'light', 'dp_amount' => '5%', 'booking_fee' => '5000000', 'booking_fee_reduces' => 'ya'];

    // ---- Isian dan pengetahuan --------------------------------------------------------------------------------

    public function test_the_wizard_stores_the_model_and_writes_it_for_the_ai(): void
    {
        $agency = $this->newAgency();
        $this->actingAs($this->adminOf($agency));
        $this->in($agency, fn () => Livewire::test(PropertySetup::class)
            ->set('data', ['project' => self::PROJECT + self::KPR_LIGHT, 'types' => self::TYPES])->call('save')->assertHasNoErrors());

        $model = $this->model($agency);
        $this->assertTrue($model->filled());
        $this->assertSame(['cash', 'kpr'], $model->options());
        $this->assertSame('cash keras atau KPR', $model->choices());

        $entry = $this->in($agency, fn () => AiKnowledgeItem::query()->where('source', Facts::KNOWLEDGE_SOURCE)->where('title', 'Model pembayaran')->value('content'));
        $this->assertStringContainsString('Cara bayar yang tersedia: cash keras; KPR.', $entry);
        $this->assertStringContainsString('Tidak tersedia: cash bertahap, cicilan developer.', $entry);
        $this->assertStringContainsString('Uang muka (DP): DP ringan, mulai 5%.', $entry);
        $this->assertStringContainsString('Tanda jadi: Rp5.000.000 (Rp5 juta), mengurangi harga.', $entry);

        $prompt = $model->prompt();
        $this->assertStringStartsWith('MODEL PEMBAYARAN AGENSI', $prompt);
        $this->assertStringContainsString('HANYA dari yang tersedia di atas (cash keras atau KPR)', $prompt);

        // The form shows it back as it was saved.
        $this->in($agency, fn () => Livewire::test(PropertySetup::class)->assertSet('data.project.pay_options', ['cash', 'kpr'])->assertSet('data.project.dp_model', 'light'));
    }

    public function test_an_empty_model_assumes_nothing_and_a_document_cannot_fill_it(): void
    {
        $agency = $this->newAgency();
        $this->fill($agency, []);

        $this->assertFalse($this->model($agency)->filled());
        $this->assertSame('', $this->model($agency)->prompt());
        $this->assertSame([], $this->model($agency)->claims('Bisa pakai KPR kak, tanpa DP juga bisa'));

        $documentKeys = array_keys(FactExtractor::tool()['input_schema']['properties']['project']['properties']);
        $this->assertNotContains('pay_options', $documentKeys);
        $this->assertNotContains('dp_model', $documentKeys);
        $this->assertContains('booking_fee', $documentKeys);
    }

    // ---- Pemeriksa berbasis fakta ------------------------------------------------------------------------------

    public function test_claims_are_held_only_when_the_model_contradicts_them(): void
    {
        $cash = $this->newAgency('agensi-bertahap');
        $this->fill($cash, self::CASH_ONLY);
        $model = $this->model($cash);

        $this->assertNotSame([], $model->claims('Bisa pakai KPR kak 😊'));
        $this->assertNotSame([], $model->claims('Bisa tanpa DP kak'));
        $this->assertNotSame([], $model->claims('Ada juga cicilan developer kak'));
        $this->assertSame([], $model->claims('Untuk KPR belum tersedia kak, saat ini cash bertahap sampai 24 bulan tanpa bunga.'));
        $this->assertSame([], $model->claims('Mohon maaf kak, pilihan KPR tidak ada. DP 30% ya kak.'));

        $kpr = $this->newAgency('agensi-kpr');
        $this->fill($kpr, self::KPR_LIGHT);
        $this->assertSame([], $this->model($kpr)->claims('Bisa pakai KPR kak, DP ringan mulai 5%.'));
        $this->assertNotSame([], $this->model($kpr)->claims('Bisa DP 0% kak'));

        // A test or forbidden phrase the model makes true passes (the old #7 failure: "bisa pakai KPR").
        $this->assertSame([], PropertyGuard::problems('Bisa pakai KPR kak', '', ['bisa pakai KPR'], '', $this->model($kpr)->granted()));
        $this->assertNotSame([], PropertyGuard::problems('Bisa pakai KPR kak', '', ['bisa pakai KPR'], '', $model->granted()));
    }

    public function test_free_is_a_fact_only_where_the_agency_says_so(): void
    {
        $free = $this->newAgency('agensi-dp-nol');
        $this->fill($free, ['pay_options' => ['cash', 'kpr'], 'dp_model' => 'free', 'dp_promo' => 'sampai 31 Desember 2026']);
        $this->assertSame([], $this->model($free)->claims('Lagi promo DP gratis kak sampai 31 Desember 2026'));
        $this->assertContains('gratis', $this->model($free)->granted());

        $light = $this->newAgency('agensi-ringan');
        $this->fill($light, self::KPR_LIGHT);
        $this->assertNotSame([], $this->model($light)->claims('DP-nya gratis kak'));
        $this->assertNotSame([], $this->model($light)->claims('Ada bonus kanopi gratis kak', 'Tipe Melati: harga Rp500 juta.'));
        $this->assertSame([], $this->model($light)->claims('Ada bonus kanopi gratis kak', 'Bonus: kanopi gratis.'), 'a free bonus in the knowledge is a fact');

        $this->assertNotContains('gratis', AiSettings::SUGGESTED_PHRASES);
        $this->assertNotContains('gratis', $free->ai_settings['forbidden_phrases']);
    }

    public function test_a_denial_or_doubt_about_a_forbidden_phrase_is_not_a_promise(): void
    {
        // The old #5 and #20 failures on agensi-demo.
        $this->assertSame([], PropertyGuard::problems('Mohon maaf Kak, saya belum punya info soal siap huni. Saya bantu tanyakan ke tim ya.', '', ['siap huni']));
        $this->assertSame([], PropertyGuard::problems('Untuk tipe 45/90 saya tidak bisa memastikan siap huni bulan depan Kak, unitnya masih inden.', '', ['siap huni']));
        $this->assertNotSame([], PropertyGuard::problems('Rumahnya sudah siap huni Kak, bisa langsung ditempati.', '', ['siap huni']));
        $this->assertNotSame([], PropertyGuard::problems('Tidak perlu khawatir, unitnya siap huni Kak.', '', ['siap huni']));
    }

    public function test_an_automatic_answer_that_offers_kpr_the_agency_does_not_have_waits_for_an_agent(): void
    {
        $agency = $this->newAgency();
        $this->fill($agency, self::CASH_ONLY);
        $agency->forceFill(['ai_settings' => array_merge($agency->ai_settings, ['auto_gate' => false, 'delay_min' => 0, 'delay_max' => 0, 'typing' => false])])->save();
        $channel = $this->in($agency, fn () => WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'Nomor Uji', 'phone' => '+6289900000021',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's']]));

        $says = fn (string $text) => $this->in($agency, fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900007781'), 'text', $text, null, CarbonImmutable::now(), 'Klien Uji')));

        $this->answer = 'Bisa pakai KPR kak, nanti saya bantu prosesnya 😊';
        $message = $says('Halo kak, bisa KPR?');
        $draft = $this->in($agency, fn () => AiDraft::query()->where('message_id', $message->getKey())->first());
        $this->assertSame(AiDraft::PENDING, $draft->status);
        $this->assertStringContainsString('menawarkan KPR', $draft->reason);

        $system = Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['system'];
        $this->assertStringContainsString('MODEL PEMBAYARAN AGENSI', is_array($system) ? json_encode($system) : $system);

        $this->travel(2)->minutes();
        $this->answer = 'Untuk KPR belum tersedia kak, saat ini pembayarannya cash bertahap sampai 24 bulan tanpa bunga 😊';
        $second = $says('Oh begitu, kalau KPR tidak bisa ya?');
        $this->assertSame(AiDraft::SENT, $this->in($agency, fn () => AiDraft::query()->where('message_id', $second->getKey())->value('status')) ?? AiDraft::SENT);
        $this->assertNull($this->in($agency, fn () => AiDraft::query()->where('message_id', $second->getKey())->where('status', AiDraft::PENDING)->first()));
    }

    // ---- Kesiapan, evaluasi, dan teks bawaan -------------------------------------------------------------------

    public function test_auto_waits_for_the_payment_model_and_the_scenarios_follow_it(): void
    {
        $agency = $this->newAgency();
        $this->fill($agency, []);
        $this->assertContains('payment', $this->in($agency, fn () => collect(AiReadiness::check()['items'])->reject(fn ($i) => $i['ok'])->pluck('key')->all()));

        $this->fill($agency, self::CASH_ONLY);
        $this->assertNotContains('payment', $this->in($agency, fn () => collect(AiReadiness::check()['items'])->reject(fn ($i) => $i['ok'])->pluck('key')->all()));

        $scenarios = collect(Scenarios::fromFacts($agency))->keyBy('title');
        $this->assertTrue($scenarios->has('KPR tidak tersedia (model pembayaran agensi)'));
        $this->assertTrue($scenarios->has('Tanpa DP tidak tersedia (model pembayaran agensi)'));
        $this->assertSame(1, preg_match(array_key_first($scenarios['Cara bayar (model pembayaran agensi)']['must']), 'cash bertahap 24 bulan'));
        $this->assertSame(1, preg_match(array_key_first($scenarios['KPR tidak tersedia (model pembayaran agensi)']['must']), 'Untuk KPR belum tersedia kak'));

        $this->fill($agency, self::KPR_LIGHT);
        $scenarios = collect(Scenarios::fromFacts($agency))->keyBy('title');
        $this->assertTrue($scenarios->has('KPR tersedia (model pembayaran agensi)'));
        $this->assertSame('answer', $scenarios['KPR tersedia (model pembayaran agensi)']['expect']);
    }

    public function test_jitus_wording_follows_the_model_for_new_agencies_only(): void
    {
        $agency = $this->newAgency();
        $this->assertTrue(PaymentDefaults::applies($agency));
        $action = fn (Tenant $t, string $stage) => $this->in($t, fn () => Stage::query()->where('name', $stage)->value('default_action'));
        $followUp = fn (Tenant $t) => $this->in($t, fn () => FollowUpRule::query()->where('name', PaymentDefaults::FOLLOW_UP)->value('body'));
        $kprReason = fn (Tenant $t) => $this->in($t, fn () => LostReason::query()->where('label', PaymentDefaults::KPR_REASON)->exists());

        $this->fill($agency, self::CASH_ONLY);
        $this->assertSame('Kirim penawaran dan simulasi pembayaran', $action($agency, 'Sudah survei'));
        $this->assertSame('Lengkapi berkas, proses pembayaran', $action($agency, 'Booking'));
        $this->assertSame('Halo Kak {nama}, kalau boleh tahu rencananya mau bayar cash bertahap?', $followUp($agency));
        $this->assertFalse($kprReason($agency));

        $this->fill($agency, self::KPR_LIGHT);
        $this->assertSame('Kirim penawaran dan simulasi KPR', $action($agency, 'Sudah survei'));
        $this->assertSame('Halo Kak {nama}, kalau boleh tahu rencananya mau bayar cash keras atau KPR?', $followUp($agency));
        $this->assertTrue($kprReason($agency));

        // The agency's own wording is never rewritten.
        $this->in($agency, fn () => FollowUpRule::query()->where('name', PaymentDefaults::FOLLOW_UP)->update(['body' => 'Kak {nama}, ada rencana cara bayar?']));
        $this->in($agency, fn () => Stage::query()->where('name', 'Booking')->update(['default_action' => 'Proses berkas versi kami']));
        $this->fill($agency, self::CASH_ONLY);
        $this->assertSame('Kak {nama}, ada rencana cara bayar?', $followUp($agency));
        $this->assertSame('Proses berkas versi kami', $action($agency, 'Booking'));

        // A reason a lead already carries stays.
        $this->fill($agency, self::KPR_LIGHT);
        $reason = $this->in($agency, fn () => LostReason::query()->where('label', PaymentDefaults::KPR_REASON)->first());
        $this->in($agency, fn () => Lead::create(['name' => 'Klien Contoh', 'phone' => '089900077790', 'stage_id' => Stage::query()->where('name', 'Gugur')->value('id'), 'lost_reason_id' => $reason->getKey()]));
        $this->fill($agency, self::CASH_ONLY);
        $this->assertTrue($kprReason($agency));
    }

    public function test_an_existing_agency_and_a_shop_are_untouched_and_models_do_not_cross(): void
    {
        // Made before the payment model, like the agencies already in production: no flag, its own texts.
        $old = $this->makeTenant('agensi-lama');
        $old->forceFill(['pipeline_settings' => null])->save();
        $before = $this->in($old, fn () => [Stage::query()->orderBy('id')->pluck('default_action')->all(), FollowUpRule::query()->orderBy('id')->pluck('body')->all(), LostReason::query()->orderBy('id')->pluck('label')->all()]);
        $this->fill($old, self::CASH_ONLY);
        $this->assertFalse(PaymentDefaults::applies($old->fresh()));
        $this->assertSame($before, $this->in($old, fn () => [Stage::query()->orderBy('id')->pluck('default_action')->all(), FollowUpRule::query()->orderBy('id')->pluck('body')->all(), LostReason::query()->orderBy('id')->pluck('label')->all()]));
        $this->assertNull($old->fresh()->ai_settings, 'its AI settings are not written');

        $kpr = $this->newAgency('agensi-kpr');
        $this->fill($kpr, self::KPR_LIGHT);
        $this->assertSame(['installment'], $this->model($old)->options());
        $this->assertSame(['cash', 'kpr'], $this->model($kpr)->options());

        $shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
        $this->assertFalse(PaymentDefaults::applies($shop));
        $this->assertFalse($this->model($shop)->filled());
    }

    // ---- Empat profil demo ---------------------------------------------------------------------------------------

    public function test_the_four_demo_payment_profiles_are_new_agencies_with_only_knowledge(): void
    {
        $this->seed(AgensiDemoPembayaranSeeder::class);

        $profiles = [
            'agensi-demo' => [['cash', 'installment', 'kpr'], 'light'],
            'agensi-demo-bertahap' => [['installment'], 'standard'],
            'agensi-demo-dp-nol' => [['cash', 'kpr'], 'free'],
            'agensi-demo-tanda-jadi' => [['cash', 'developer'], 'none'],
        ];

        foreach ($profiles as $slug => [$options, $dp]) {
            $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
            $model = $this->model($tenant);

            $this->assertSame(AiSettings::NEW_PROPERTY, $tenant->ai_settings, $slug.': AI settings untouched');
            $this->assertSame([$options, $dp], [$model->options(), $model->downPayment()], $slug);
            $missing = $this->in($tenant, fn () => collect(AiReadiness::check()['items'])->reject(fn ($i) => $i['ok'])->pluck('key')->all());
            $this->assertSame([], array_diff($missing, ['test', 'review']), $slug.': only "Uji AI saya" may be missing');
            $this->assertSame(in_array('kpr', $options, true), $this->in($tenant, fn () => LostReason::query()->where('label', PaymentDefaults::KPR_REASON)->exists()), $slug);
        }
    }
}
