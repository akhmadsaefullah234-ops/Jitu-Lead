<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\DigitalTools;
use App\Automation\PropertyStageAutomationSettings;
use App\Automation\RetailStages;
use App\Automation\StageAutomationSettings;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\StageAutomationPage;
use App\Mail\PaymentProofReceived;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\Tracking\PipelineConversions;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding the "digital" industry changes nothing for a shop (like Quicker Sport) or a property agency: the same AI
 * tools, the same stage automation page and storage, the same Meta event defaults and the same e-mail text.
 */
class DigitalRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Baik, Kak.']]]),
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

    private function chat(Tenant $tenant, string $text): void
    {
        $channel = $this->in($tenant, fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+628990000002', 'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => AiMode::Auto,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));

        $this->in($tenant, fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize('6289900001234'), 'text', $text, null, CarbonImmutable::now(), 'Sari',
        )));
    }

    /** @return list<string> the tool names the model was offered */
    private function offeredTools(): array
    {
        $request = Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'))->first()[0]->data();

        return array_column($request['tools'] ?? [], 'name');
    }

    public function test_a_shop_keeps_its_order_tools_and_never_sees_the_digital_tool(): void
    {
        $shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->in($shop, fn () => Property::create(['name' => 'Kaos Lari', 'price_from' => 89000, 'weight_grams' => 300]));

        $this->chat($shop, 'Kak kaos lari ada?');

        $tools = $this->offeredTools();
        $this->assertContains('catat_pesanan', $tools);
        $this->assertNotContains(DigitalTools::RECORD, $tools);
        $this->assertArrayNotHasKey('digital', (array) $shop->fresh()->stage_automation);
    }

    public function test_a_property_agency_never_sees_the_digital_tool(): void
    {
        $agency = app(ProvisionTenant::class)('Griya Contoh', 'griya-contoh', User::factory()->create(), 'properti');

        $this->chat($agency, 'Halo, rumah tipe 36 masih ada?');

        $this->assertNotContains(DigitalTools::RECORD, $this->offeredTools());
        $this->assertTrue(PropertyStageAutomationSettings::of($agency->fresh())->chosen());
        $this->assertArrayNotHasKey('digital', (array) $agency->fresh()->stage_automation);
    }

    public function test_the_shop_automation_page_and_its_storage_are_unchanged(): void
    {
        $shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->actingInTenant($this->adminOf($shop), $shop);

        $state = StageAutomationSettings::of($shop)->toArray();
        $state['no_payment']['enabled'] = false;
        Livewire::test(StageAutomationPage::class)->assertSee('Order masuk')->assertDontSee('Lunas & akses terkirim')->set('data', $state)->call('save');

        $this->assertSame(StageAutomationSettings::clean($state), $shop->fresh()->stage_automation, 'a shop still stores its rules at the top level');
    }

    public function test_meta_defaults_and_mail_text_for_shops_and_property_are_unchanged(): void
    {
        $shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $retail = $this->in($shop, fn () => PipelineConversions::defaults('retail'));
        $stages = $this->in($shop, fn () => [(new RetailStages)->stage(RetailStages::ORDER)->id => 'InitiateCheckout', (new RetailStages)->stage(RetailStages::SHIPPING)->id => 'Purchase']);
        $this->assertSame($stages, $retail['stages']);
        $this->assertSame('Lead', $retail['hot']);

        $agency = app(ProvisionTenant::class)('Griya Contoh', 'griya-contoh', User::factory()->create(), 'properti');
        $property = $this->in($agency, fn () => PipelineConversions::defaults('properti'));
        $closing = $this->in($agency, fn () => Stage::query()->where('name', 'Closing')->value('id'));
        $this->assertSame('Purchase', $property['stages'][$closing]);

        $lead = $this->in($shop, fn () => Lead::create(['name' => 'Budi', 'phone' => '089900001111', 'stage_id' => Stage::query()->value('id')]));
        $this->assertStringContainsString('agar pesanan lanjut ke tahap dikemas dan dikirim', (new PaymentProofReceived($shop, $lead))->render());
    }
}
