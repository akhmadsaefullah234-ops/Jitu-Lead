<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\Evaluation\Scenarios;
use App\Ai\Facts\Facts;
use App\Ai\Facts\PaymentModel;
use App\Ai\PropertyGuard;
use App\Ai\PropertyPlaybook;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiDraft;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PR 9: a phrase named as the topic is not a claim, a survey day the client named is repeated with concrete hours,
 * and "tanpa DP?" gets a plain answer from the agency's payment model. Every agency, client and number is made up;
 * phone numbers start 0899000.
 */
class PropertyAnswerRulesTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = [
        'project' => 'Perumahan Contoh Bertahap', 'location' => 'Jl. Contoh Raya 1, Kota Contoh', 'legal' => 'SHM', 'handover' => '12 bulan',
        'survey_hours' => 'Setiap hari 09.00 sampai 16.00', 'contact' => 'Kantor pemasaran',
    ];

    private const TYPES = [
        ['name' => '36/72', 'price' => '480000000', 'building_area' => '36', 'land_area' => '72', 'availability' => 'Tersedia'],
        ['name' => '45/90', 'price' => '620000000', 'building_area' => '45', 'land_area' => '90', 'availability' => 'Inden'],
    ];

    private const STANDARD = ['pay_options' => ['installment'], 'installment_tenor' => '24', 'installment_interest' => 'tidak', 'dp_model' => 'standard',
        'dp_amount' => '30%', 'booking_fee' => '5000000', 'booking_fee_reduces' => 'tidak'];

    private const LIGHT = ['pay_options' => ['cash', 'kpr'], 'dp_model' => 'light', 'dp_amount' => '5%'];

    private const ZERO = ['pay_options' => ['cash', 'kpr'], 'dp_model' => 'zero'];

    private int $n = 0;

    /** @var list<string> the model's answers, in order */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => array_shift($this->answers) ?? 'Baik Kak 😊']]]),
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

    /** @param  array<string, mixed>  $payment */
    private function agency(array $payment, string $slug = 'agensi-uji'): Tenant
    {
        $agency = app(ProvisionTenant::class)('Agensi '.$slug, $slug, User::factory()->create(), 'properti');
        $this->in($agency, fn () => Facts::saveWizard(['project' => self::PROJECT + $payment, 'types' => self::TYPES]));
        $agency->forceFill(['ai_settings' => array_merge($agency->ai_settings, ['auto_gate' => false, 'delay_min' => 0, 'delay_max' => 0, 'typing' => false])])->save();

        return $agency;
    }

    /** The client writes; returns what was sent back, and the requests the model got. */
    private function says(Tenant $tenant, string $text): array
    {
        $channel = $this->in($tenant, fn () => WaChannel::query()->first() ?? WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'Nomor Uji',
            'phone' => '+6289900000041', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's']]));
        $before = count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic')));
        $message = $this->in($tenant, fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900007801'), 'text', $text, null, CarbonImmutable::now(), 'Rani')));
        $draft = $this->in($tenant, fn () => AiDraft::query()->where('message_id', $message->getKey())->first());
        $requests = array_slice(Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->all(), $before);

        return [$draft, array_map(fn (array $pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $requests)];
    }

    // ---- 1. Frasa sebagai topik ----------------------------------------------------------------------------------

    public function test_a_phrase_named_as_the_topic_is_not_a_claim(): void
    {
        // tanda-jadi #5.
        $answer = 'Halo Kak, untuk status siap huni, unit Tipe 36/72 tersedia, sedangkan Tipe 45/90 masih inden ... untuk ditempati bulan depan saya belum bisa memastikan dari informasi yang ada.';
        $this->assertSame([], PropertyGuard::problems($answer, '', ['siap huni']));
        $this->assertSame([], PropertyGuard::problems('Soal siap huni, saya cek dulu ke tim ya Kak.', '', ['siap huni']));
        $this->assertSame([], PropertyGuard::problems('Terkait siap huni, tipe 45/90 masih inden Kak.', '', ['siap huni']));
        $this->assertSame([], PropertyGuard::problems('Mengenai siap huni, unit 36/72 tersedia Kak.', '', ['siap huni']));

        // The same rule for the payment phrases.
        $standard = $this->agency(self::STANDARD);
        $model = $this->in($standard, fn () => PaymentModel::current());
        $this->assertSame([], $model->claims('Terkait DP 0, yang berlaku DP 30% ya Kak.'));
        $this->assertSame([], $model->claims('Untuk KPR, saat ini belum tersedia Kak.'));
        $this->assertSame([], $model->claims('Soal gratis, belum ada promo saat ini Kak.'));

        // Real claims are still held, also right after a topic.
        $this->assertNotSame([], PropertyGuard::problems('Unit ini siap huni Kak.', '', ['siap huni']));
        $this->assertNotSame([], PropertyGuard::problems('Untuk tipe 45/90 sudah siap huni Kak.', '', ['siap huni']));
        $this->assertNotSame([], $model->claims('Soal KPR, bisa pakai KPR kok Kak.'));
        $this->assertNotSame([], $model->claims('Terkait DP, bisa DP 0 Kak.'));
    }

    // ---- 2. Hari survei dari klien -----------------------------------------------------------------------------

    public function test_a_survey_day_the_client_names_is_repeated_with_hours_before_the_type(): void
    {
        $agency = $this->agency(self::STANDARD);
        $playbook = PropertyPlaybook::instructions($agency);
        $this->assertStringContainsString('Bila klien sudah menyebut hari atau waktu survei, ulangi hari itu lebih dulu dan tawarkan dua jam konkret', $playbook);
        $this->assertStringContainsString('Bila tipe belum jelas, tanyakan tipenya di pesan yang sama, setelah hari dan jam', $playbook);

        // bertahap #16: the day was dropped for a question about the type. The AI is asked once to fix it.
        $this->answers = [
            'Boleh banget kak Rani 😊 Tapi sebelum saya catatkan, boleh saya tahu mau survei yang mana, Tipe 36/72 atau Tipe 45/90?',
            'Sabtu ya Kak Rani 😊 Mau pagi jam 10 atau siang jam 1? Sekalian, mau lihat Tipe 36/72 atau Tipe 45/90?',
        ];
        [$draft, $requests] = $this->says($agency, 'Boleh kak, Sabtu besok bisa survei?');

        $this->assertCount(2, $requests);
        $this->assertStringContainsString('PERBAIKI: klien menyebut hari Sabtu', $requests[1]);
        $this->assertSame(AiDraft::SENT, $draft->status);
        $sent = $this->in($agency, fn () => WaMessage::query()->where('direction', 'out')->latest('id')->value('body'));
        $this->assertStringContainsString('Sabtu', $sent);
        $this->assertMatchesRegularExpression('/jam \d+.*jam \d+/u', $sent);
        $this->assertStringContainsString('Tipe 36/72 atau Tipe 45/90', $sent);

        // A day and a type: hours straight away, nothing to fix.
        $this->assertNull(PropertyPlaybook::dayNote('Sabtu mau lihat tipe 36/72 kak', 'Sabtu ya Kak, tipe 36/72 bisa pagi jam 10 atau siang jam 1?'));
        $this->assertNull(PropertyPlaybook::dayNote('2 minggu lagi saya ke lokasi kak', 'Baik Kak'), 'a number of weeks is not a day');
        $this->assertNull(PropertyPlaybook::dayNote('Saya kerja Senin sampai Jumat', 'Baik Kak'), 'a day that is not about a visit');
    }

    // ---- 3. "Ada yang tanpa DP?" -------------------------------------------------------------------------------

    public function test_a_question_about_no_down_payment_gets_a_plain_answer_from_the_model(): void
    {
        // Standard DP (bertahap #28): the amount alone is not an answer.
        $standard = $this->agency(self::STANDARD);
        $model = $this->in($standard, fn () => PaymentModel::current());
        $this->assertTrue($model->hasDownPayment());
        $this->assertSame('Untuk DP, tidak ada pilihan tanpa DP ya Kak. Yang berlaku DP 30% dan tanda jadi Rp5.000.000 (Rp5 juta) di luar harga.', $model->noDownPaymentAnswer());
        $this->assertStringContainsString('jawab tegas dulu bahwa pilihan tanpa DP tidak tersedia', $model->prompt());
        $this->assertNotNull($model->noDownPaymentNote('Ada yang tanpa DP kak?', 'Untuk DP kami berlaku DP 30% ya Kak. Cara bayar: cash bertahap sampai 24 bulan, tanpa bunga.'));
        $this->assertNull($model->noDownPaymentNote('Ada yang tanpa DP kak?', $model->noDownPaymentAnswer()));
        $this->assertNull($model->noDownPaymentNote('Harganya berapa kak?', 'Rp480 juta Kak.'), 'not asked');

        $this->answers = ['Untuk DP kami berlaku DP 30% ya Kak 😊', 'Untuk DP, tidak ada pilihan tanpa DP ya Kak. Yang berlaku DP 30% dan tanda jadi Rp5 juta di luar harga 😊'];
        [$draft, $requests] = $this->says($standard, 'Ada yang tanpa DP kak?');
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('PERBAIKI: klien menanyakan tanpa DP', $requests[1]);
        $this->assertSame(AiDraft::SENT, $draft->status);

        // Light DP: the same no, with the light amount.
        $light = $this->in($this->agency(self::LIGHT, 'agensi-ringan'), fn () => PaymentModel::current());
        $this->assertStringContainsString('tidak ada pilihan tanpa DP ya Kak. Yang berlaku DP ringan, mulai 5%', $light->noDownPaymentAnswer());
        $this->assertNotNull($light->noDownPaymentNote('Bisa DP 0 kak?', 'DP ringan mulai 5% Kak.'));
        $this->assertNull($light->noDownPaymentNote('Bisa DP 0 kak?', 'Untuk DP 0 belum tersedia ya Kak, yang berlaku DP ringan mulai 5%.'));

        // DP 0% (dp-nol): answered as the model says, never declined.
        $zero = $this->in($this->agency(self::ZERO, 'agensi-nol'), fn () => PaymentModel::current());
        $this->assertFalse($zero->hasDownPayment());
        $this->assertStringContainsString('jawab bahwa yang tersedia: DP 0%', $zero->prompt());
        $this->assertNull($zero->noDownPaymentNote('Ada yang tanpa DP kak?', 'Ada Kak, tersedia DP 0% 😊'));
        $this->assertNotNull($zero->noDownPaymentNote('Ada yang tanpa DP kak?', 'Bisa KPR Kak 😊'));

        // The evaluation asks for the plain no where the agency has a down payment.
        $scenario = $this->in($standard, fn () => collect(Scenarios::fromFacts($standard))->firstWhere('title', 'Tanpa DP tidak tersedia (model pembayaran agensi)'));
        $this->assertSame(0, preg_match(array_key_first($scenario['must']), 'untuk DP kami berlaku DP 30% ya Kak'));
        $this->assertSame(1, preg_match(array_key_first($scenario['must']), 'Untuk DP, tidak ada pilihan tanpa DP ya Kak'));
    }
}
