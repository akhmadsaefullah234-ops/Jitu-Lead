<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\Facts\Facts;
use App\Ai\Facts\PaymentModel;
use App\Ai\PropertyGuard;
use App\Ai\PropertyTools;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiDraft;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PR 10: "Bisa pakai KPR?" is always answered where KPR is offered, also when the model writes its answer next to
 * simpan_kebutuhan and ends with nothing after the result (dp-nol #27), and a list of ways said not to exist is a
 * denial, not an offer (bertahap #26). Every agency, client and number is made up; phone numbers start 0899000.
 */
class PaymentAnswerConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = [
        'project' => 'Perumahan Contoh Nol', 'location' => 'Jl. Contoh Raya 1, Kota Contoh', 'legal' => 'SHM', 'handover' => '12 bulan',
        'survey_hours' => 'Setiap hari 09.00 sampai 16.00', 'contact' => 'Kantor pemasaran',
    ];

    private const TYPES = [['name' => '36/72', 'price' => '480000000', 'building_area' => '36', 'land_area' => '72', 'availability' => 'Tersedia']];

    /** dp-nol: cash and KPR, free down payment. */
    private const DP_NOL = ['pay_options' => ['cash', 'kpr'], 'kpr_banks' => 'Bank Contoh', 'dp_model' => 'free', 'dp_promo' => 'sampai 31 Desember 2026'];

    /** bertahap: cash in instalments only, 30% down. */
    private const BERTAHAP = ['pay_options' => ['installment'], 'installment_tenor' => '24', 'installment_interest' => 'tidak', 'dp_model' => 'standard',
        'dp_amount' => '30%', 'booking_fee' => '5000000', 'booking_fee_reduces' => 'tidak'];

    private int $n = 0;

    /** @var list<array<string, mixed>> the model's responses, in order */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(array_shift($this->responses) ?? self::text('Baik Kak 😊')),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private static function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]]];
    }

    /** The answer written next to simpan_kebutuhan, as the model often does. */
    private static function textWithNeeds(string $text, array $input): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'text', 'text' => $text],
            ['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => PropertyTools::NEEDS, 'input' => $input]]];
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $fn);
    }

    /** @param  array<string, mixed>  $payment */
    private function agency(array $payment, string $slug): Tenant
    {
        $agency = app(ProvisionTenant::class)('Agensi '.$slug, $slug, User::factory()->create(), 'properti');
        $this->in($agency, fn () => Facts::saveWizard(['project' => self::PROJECT + $payment, 'types' => self::TYPES]));
        $agency->forceFill(['ai_settings' => array_merge($agency->ai_settings, ['auto_gate' => false, 'delay_min' => 0, 'delay_max' => 0, 'typing' => false])])->save();

        return $agency;
    }

    private function asks(Tenant $tenant, string $text, string $phone): AiDraft
    {
        $channel = $this->in($tenant, fn () => WaChannel::query()->first() ?? WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'Nomor Uji',
            'phone' => '+6289900000051', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's']]));
        $message = $this->in($tenant, fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), 'Klien Uji')));

        return $this->in($tenant, fn () => AiDraft::query()->where('message_id', $message->getKey())->firstOrFail());
    }

    public function test_a_kpr_question_is_always_answered_where_kpr_is_offered(): void
    {
        $agency = $this->agency(self::DP_NOL, 'agensi-dp-nol');
        $answer = 'Bisa Kak, pembeliannya bisa pakai KPR lewat Bank Contoh 😊 Saat ini juga ada promo DP gratis sampai 31 Desember 2026. Mau saya bantu hitung simulasinya?';

        // dp-nol #27, five times: the answer next to simpan_kebutuhan and nothing after the result (the shape that
        // was handed over twice, "terpotong atau ditolak"), and the answer after the result.
        foreach (range(1, 5) as $i) {
            $this->responses = $i % 2 === 1
                ? [self::textWithNeeds($answer, ['cara_bayar' => 'kpr']), self::text('')]
                : [self::textWithNeeds('', ['cara_bayar' => 'kpr']), self::text($answer)];

            $draft = $this->asks($agency, 'Bisa pakai KPR nggak kak?', '62899000078'.(10 + $i));

            $this->assertSame(AiDraft::SENT, $draft->status, "run {$i}: ".$draft->reason);
            $this->assertStringContainsString('KPR', $draft->body);
            $this->assertSame([], $this->in($agency, fn () => PaymentModel::current()->claims($draft->body, PropertyGuard::sources())));
        }

        // The need was saved each time.
        $this->assertSame(5, $this->in($agency, fn () => Lead::query()->whereNotNull('payment_method')->count()));
    }

    public function test_a_kpr_question_where_kpr_is_not_offered_is_answered_honestly(): void
    {
        $agency = $this->agency(self::BERTAHAP, 'agensi-bertahap');

        foreach (range(1, 5) as $i) {
            $this->responses = [self::text('Untuk KPR belum tersedia ya Kak. Yang ada saat ini cash bertahap sampai 24 bulan tanpa bunga 😊')];
            $draft = $this->asks($agency, 'Bisa pakai KPR nggak kak?', '62899000079'.(10 + $i));
            $this->assertSame(AiDraft::SENT, $draft->status, "run {$i}: ".$draft->reason);
        }

        // An offer of KPR is still held.
        $this->responses = [self::text('Bisa pakai KPR kok Kak 😊')];
        $this->assertSame(AiDraft::PENDING, $this->asks($agency, 'Bisa pakai KPR nggak kak?', '6289900007990')->status);
    }

    public function test_text_written_before_a_survey_is_not_taken_as_the_answer(): void
    {
        $agency = $this->agency(self::DP_NOL, 'agensi-survei');
        // A survey tool call with text and nothing after: still the empty-answer path (retry, then a person).
        $survey = ['stop_reason' => 'tool_use', 'content' => [['type' => 'text', 'text' => 'Siap Kak, saya catat surveinya.'],
            ['type' => 'tool_use', 'id' => 'tu_s', 'name' => PropertyTools::SURVEY, 'input' => ['tanggal' => '2026-11-21', 'jam' => '10:00', 'lokasi' => 'Rumah contoh']]]];
        $this->responses = [$survey, self::text(''), $survey, self::text('')];

        $draft = $this->asks($agency, 'Sabtu jam 10 saya survei ya kak', '6289900007995');
        $this->assertNotSame(AiDraft::SENT, $draft->status);
    }

    public function test_a_list_of_ways_said_not_to_exist_is_a_denial(): void
    {
        $agency = $this->agency(self::BERTAHAP, 'agensi-daftar');
        $model = $this->in($agency, fn () => PaymentModel::current());

        // bertahap #26.
        $answer = 'Untuk pembayaran, yang tersedia saat ini cash bertahap sampai 24 bulan, tanpa bunga. Uang muka (DP) 30%, dan tanda jadi Rp5.000.000 di luar harga. '
            .'Cash keras, cicilan developer, dan KPR belum tersedia ya Kak.';
        $this->assertSame([], $model->claims($answer));
        $this->assertSame([], $model->claims('Kami tidak menyediakan cash keras, cicilan developer, atau KPR ya Kak.'));
        $this->assertSame([], $model->claims('Cicilan developer maupun KPR tidak ada ya Kak.'));

        // A real offer is still held.
        $this->assertNotSame([], $model->claims('Tersedia cicilan developer sampai 60 bulan Kak.'));
        $this->assertNotSame([], $model->claims('Bisa cicilan developer, dan KPR juga bisa Kak.'));
    }
}
