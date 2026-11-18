<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\Facts\Facts;
use App\Ai\Facts\PaymentModel;
use App\Ai\ProductContext;
use App\Ai\PropertyGuard;
use App\Ai\PropertyTypes;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Models\AiKnowledgeItem;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
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
 * PR 8: a client who names a unit type ("berapa harga tipe 36/72?") is answered from that type's data before the lead
 * has a product, an agency with a few types always gives the AI a summary of them, and a phrase in a sentence that
 * denies or doubts it is not a promise. Every agency, client and number is made up; phone numbers start 0899000.
 */
class PropertyTypeKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = [
        'project' => 'Perumahan Contoh Bertahap', 'location' => 'Jl. Contoh Raya 1, Kota Contoh', 'legal' => 'SHM', 'handover' => '12 bulan',
        'survey_hours' => 'Setiap hari 09.00 sampai 16.00', 'contact' => 'Kantor pemasaran',
        'pay_options' => ['cash', 'kpr'], 'dp_model' => 'light', 'dp_amount' => '5%',
    ];

    private const TYPES = [
        ['name' => '36/72', 'price' => '480000000', 'building_area' => '36', 'land_area' => '72', 'bedrooms' => '2', 'availability' => 'Tersedia'],
        ['name' => '45/90', 'price' => '620000000', 'building_area' => '45', 'land_area' => '90', 'bedrooms' => '3', 'availability' => 'Inden'],
    ];

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

    private function agency(string $slug = 'agensi-tipe'): Tenant
    {
        $agency = app(ProvisionTenant::class)('Agensi '.$slug, $slug, User::factory()->create(), 'properti');
        $this->in($agency, fn () => Facts::saveWizard(['project' => self::PROJECT, 'types' => self::TYPES]));
        $agency->forceFill(['ai_settings' => array_merge($agency->ai_settings, ['auto_gate' => false, 'delay_min' => 0, 'delay_max' => 0, 'typing' => false])])->save();

        return $agency;
    }

    /** The client writes; returns the system prompt the model got. */
    private function says(Tenant $tenant, string $text, string $phone = '6289900007791'): string
    {
        $channel = $this->in($tenant, fn () => WaChannel::query()->first() ?? WaChannel::create(['type' => WaChannelType::Gateway, 'name' => 'Nomor Uji',
            'phone' => '+6289900000031', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's']]));
        $this->in($tenant, fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($phone), 'text', $text, null, CarbonImmutable::now(), 'Klien Uji')));

        $system = Http::recorded(fn (Request $r) => str_contains($r->url(), 'anthropic'))->last()[0]['system'];

        return is_array($system) ? json_encode($system, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $system;
    }

    public function test_a_lead_without_a_product_who_names_a_type_is_answered_from_that_type(): void
    {
        $agency = $this->agency();
        $this->answer = 'Tipe 36/72 harganya Rp480 juta Kak, luas bangunan 36 m2 dan luas tanah 72 m2 😊';

        $system = $this->says($agency, 'Halo kak, berapa harga tipe 36/72?');

        // The type's own knowledge entry (linked to its property) and its catalogue data are read.
        $this->assertStringContainsString('Tipe 36/72:', $system);
        $this->assertStringContainsString('Pelanggan ini menanyakan rumah: Perumahan Contoh Bertahap - Tipe 36/72', str_replace('properti:', 'rumah:', $system));
        $this->assertStringContainsString('Harga: Rp480.000.000', $system);

        // The lead now has that type as its product, as if the AI had written "PRODUK: <id>".
        $lead = $this->in($agency, fn () => Lead::query()->latest('id')->first());
        $type = $this->in($agency, fn () => Property::query()->where('property_type', '36/72')->first());
        $this->assertSame($type->getKey(), $lead->property_id);
        $this->assertSame('ai', $lead->product_source);
    }

    public function test_spelling_variants_of_a_type_are_understood(): void
    {
        $agency = $this->agency();

        $this->in($agency, function () {
            $products = Property::query()->get();

            foreach (['harga tipe 36/72?', 'tipe 36 / 72 berapa', 'yg 36-72 brp kak', 'luas tipe 45 berapa?', 'Type 45x90 masih ada?'] as $q) {
                $this->assertNotNull(PropertyTypes::named($q, $products), $q);
            }

            $this->assertSame('45/90', PropertyTypes::named('Type 45x90 masih ada?', $products)->property_type);
            $this->assertNull(PropertyTypes::named('harga rumahnya berapa kak?', $products), 'no type named');
            $this->assertNull(PropertyTypes::named('bedanya 36/72 dan 45/90 apa?', $products), 'two types named: the AI asks or compares');
        });
    }

    public function test_a_question_that_names_no_type_is_answered_from_the_summary(): void
    {
        $agency = $this->agency();

        $system = $this->says($agency, 'Kak harganya berapa ya?');

        $this->assertStringContainsString('RINGKASAN TIPE', $system);
        $this->assertStringContainsString('Perumahan Contoh Bertahap - Tipe 36/72: harga Rp480.000.000 (Rp480 juta); luas bangunan 36 m2; luas tanah 72 m2; 2 kamar tidur; tersedia.', $system);
        $this->assertStringContainsString('Perumahan Contoh Bertahap - Tipe 45/90: harga Rp620.000.000 (Rp620 juta); luas bangunan 45 m2; luas tanah 90 m2; 3 kamar tidur; inden.', $system);
        $this->assertStringContainsString('RINGKASAN TIPE memuat harga dan luas', $system);
        $this->assertStringNotContainsString('Jangan menyebut harga sebelum', $system);
        $this->assertNull($this->in($agency, fn () => Lead::query()->latest('id')->value('property_id')), 'no type named, none chosen');
    }

    public function test_the_summary_is_only_for_property_agencies_with_a_few_types_and_never_rewrites_their_knowledge(): void
    {
        $agency = $this->agency();
        $manual = $this->in($agency, fn () => AiKnowledgeItem::query()->create(['title' => 'Ringkasan tipe', 'content' => 'Ringkasan buatan agensi.', 'source' => 'manual', 'active' => true]));
        $this->says($agency, 'Kak harganya berapa ya?');
        $this->assertSame('Ringkasan buatan agensi.', $manual->fresh()->content);

        $many = app(ProvisionTenant::class)('Agensi Besar', 'agensi-besar', User::factory()->create(), 'properti');
        $this->in($many, function () {
            foreach (range(1, PropertyTypes::MAX + 1) as $i) {
                Property::query()->create(['kind' => 'primary', 'name' => "Tipe Contoh {$i}", 'property_type' => "{$i}0/{$i}5", 'price_from' => 100_000_000 * $i, 'status' => 'available']);
            }
            $this->assertNull((new ProductContext(null))->summary());
        });

        $shop = app(ProvisionTenant::class)('Toko Contoh', 'toko-contoh', User::factory()->create(), 'retail');
        $this->in($shop, function () {
            Property::query()->create(['kind' => 'primary', 'name' => 'Kaos Contoh 36/72', 'price_from' => 50_000, 'status' => 'available']);
            Property::query()->create(['kind' => 'primary', 'name' => 'Celana Contoh', 'price_from' => 80_000, 'status' => 'available']);
            $context = (new ProductContext(null))->asked('harga kaos contoh 36/72 berapa?');
            $this->assertNull($context->summary(), 'a shop gets no type summary');
            $this->assertNull($context->named(), 'a shop keeps its own product matching');
        });
    }

    // ---- Penyangkalan dan keraguan ----------------------------------------------------------------------------

    public function test_a_denial_or_doubt_is_not_a_claim_and_a_real_claim_still_is(): void
    {
        // The #5/#20 answer on every profile, and #28 on agensi-demo.
        $this->assertSame([], PropertyGuard::problems('Status siap huni untuk tipe 45/90 saya belum punya info pastinya di sini Kak, saya bantu tanyakan ke tim ya.', '', ['siap huni']));
        $dp = 'Untuk DP sendiri kami tidak menawarkan tanpa DP atau DP 0 ya Kak. Yang tersedia DP ringan mulai 5%.';
        $this->assertSame([], PropertyGuard::problems($dp, 'DP ringan mulai 5%', ['tanpa DP', 'DP 0']));
        $this->assertSame([], PropertyGuard::problems('Mohon maaf Kak, permintaan DP 0 belum bisa kami penuhi.', '', ['DP 0']));

        $agency = $this->agency();
        $model = $this->in($agency, fn () => PaymentModel::current());
        $this->assertSame('light', $model->downPayment());
        $this->assertSame([], $model->claims($dp), 'matches the agency\'s light down payment');

        // Real claims are still held.
        $this->assertNotSame([], PropertyGuard::problems('Unit ini siap huni Kak, bisa langsung ditempati.', '', ['siap huni']));
        $this->assertNotSame([], PropertyGuard::problems('Rumahnya siap huni tanpa renovasi Kak.', '', ['siap huni']));
        $this->assertNotSame([], $model->claims('Bisa DP 0 tanpa bunga Kak.'));
        $this->assertNotSame([], $model->claims('Tenang Kak, tanpa DP juga bisa.'));
    }
}
