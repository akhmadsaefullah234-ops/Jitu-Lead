<?php

namespace Tests\Feature;

use App\Actions\HandleInboundWhatsApp;
use App\Actions\ProvisionTenant;
use App\Ai\KnowledgeBase;
use App\Catalog\ProductMatcher;
use App\Enums\AiMode;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use App\Support\PhoneNumber;
use App\WhatsApp\GatewayUrlGuard;
use App\WhatsApp\InboundMessage;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Many products advertised at once: the ad (or the first message) decides which product a chat is about, the AI
 * answers from that product only (or asks when it is not clear), and agents and the AI can change it.
 */
class ProductFromAdsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private int $n = 0;

    private string $aiAnswer = 'Baik, Kak.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        config(['services.anthropic.key' => 'test-key', 'whatsapp.allow_private_gateway_hosts' => false]);
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);

        Http::fake([
            'api.anthropic.com/*' => fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->aiAnswer]]]),
            'gw.example.com/*' => fn () => Http::response(['id' => 'g'.++$this->n]),
        ]);
    }

    protected function tearDown(): void
    {
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    /** @return array{0: Property, 1: Property, 2: Property} */
    private function catalog(): array
    {
        return $this->in(fn () => [
            Property::create(['name' => 'Raket Capra Pro', 'price_from' => 349000, 'compare_price' => 499000, 'promo_text' => 'Diskon 30% minggu ini',
                'details' => ['Warna' => 'Merah, Biru'], 'weight_grams' => 400, 'ad_keywords' => ['badminton', 'capra', '120210000000001']]),
            Property::create(['name' => 'Tas Selempang Sport', 'price_from' => 129000, 'ad_keywords' => ['selempang', 'tas olahraga']]),
            Property::create(['name' => 'Kaos Lari', 'price_from' => 99000, 'ad_keywords' => ['kaos', 'jersey']]),
        ]);
    }

    private function gateway(AiMode $mode = AiMode::Draft): WaChannel
    {
        return $this->in(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002',
            'status' => WaChannelStatus::Connected, 'position' => 1, 'ai_mode' => $mode,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function inbound(WaChannel $channel, string $text, ?array $referral = null, string $from = '6281234567890'): WaMessage
    {
        return $this->in(fn () => app(HandleInboundWhatsApp::class)->message($channel, new InboundMessage(
            'in-'.++$this->n, PhoneNumber::normalize($from), 'text', $text, null, CarbonImmutable::now(), 'Rina', $referral,
        )));
    }

    private function lead(string $phone = '+6281234567890'): Lead
    {
        return $this->in(fn () => Lead::query()->with('property')->where('phone', $phone)->firstOrFail());
    }

    private function lastSystem(): string
    {
        $calls = Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'));

        return (string) $calls->last()[0]['system'];
    }

    // ---- 1. The gateway passes the ad on -------------------------------------------------------------------------

    public function test_the_gateway_webhook_carries_the_click_to_whatsapp_ad_onto_the_lead_like_the_cloud_api(): void
    {
        [$raket] = $this->catalog();
        $gw = $this->gateway(AiMode::Off);
        $body = json_encode(['event' => 'message', 'id' => 'g1', 'from' => '6281299990000', 'type' => 'text', 'text' => 'Halo kak', 'timestamp' => time(),
            'referral' => ['source_id' => '120210000000001', 'source_type' => 'ad', 'headline' => 'Promo raket', 'body' => 'Diskon 30%',
                'source_url' => 'https://fb.me/abc', 'ctwa_clid' => 'Afc_1', 'thumbnail_url' => 'https://scontent.example/t.jpg', 'evil' => '<script>']]);

        $this->call('POST', "/webhooks/whatsapp/gateway/{$gw->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json', 'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body)->assertOk();

        $lead = $this->lead('+6281299990000');
        $this->assertSame(['120210000000001', 'Promo raket', 'https://fb.me/abc'], [$lead->ad_id, $lead->ad_title, $lead->ad_url]);
        $this->assertSame('Iklan Meta', $this->in(fn () => $lead->source->name));
        $this->assertArrayNotHasKey('evil', $lead->custom_fields['iklan']);
        $this->assertSame('https://scontent.example/t.jpg', $lead->custom_fields['iklan']['thumbnail_url']);
        $this->assertSame($raket->getKey(), $lead->property_id);
        $this->assertSame('ad_id', $lead->product_source);
        $this->assertNotNull($this->in(fn () => $lead->conversation()->value('ad_entry_at')));

        // The lead page and the Inbox show the ad.
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(EditLead::class, ['record' => $lead->getKey()])->assertSee('Iklan asal')->assertSee('Promo raket')->assertSee('120210000000001');
        Livewire::test(Inbox::class, ['leadId' => $lead->getKey()])->assertSee('Dari iklan:')->assertSee('Promo raket')->assertSee('Raket Capra Pro');
    }

    // ---- 2. Deciding the product ---------------------------------------------------------------------------------

    public function test_the_product_comes_from_the_ad_id_then_the_ad_text_then_the_first_message(): void
    {
        [$raket, $tas, $kaos] = $this->catalog();
        $matcher = app(ProductMatcher::class);
        $match = fn (?array $ad, ?string $text) => $this->in(fn () => $matcher->match($ad, $text));

        $byId = $match(['source_id' => '120210000000001', 'headline' => 'Tas selempang murah'], 'mau kaos');
        $this->assertSame([$raket->getKey(), 'ad_id'], [$byId['product']->getKey(), $byId['source']], 'the ad id wins over its text and the message');

        $byText = $match(['source_id' => '999', 'headline' => 'Tas Olahraga anti air', 'body' => 'Promo'], 'mau tanya kaos');
        $this->assertSame([$tas->getKey(), 'ad_text', 'tas olahraga'], [$byText['product']->getKey(), $byText['source'], $byText['match']]);

        $byWord = $match(null, 'Kak, yang BADMINTON masih ada?');
        $this->assertSame([$raket->getKey(), 'keyword'], [$byWord['product']->getKey(), $byWord['source']]);
        $this->assertSame($kaos->getKey(), $match(null, 'kaos lari ukuran L')['product']->getKey(), 'the product name counts too');

        $this->assertNull($match(null, 'halo kak, ada promo?'), 'nothing to go on');
        $this->assertNull($match(null, 'raket capra sama tas selempang'), 'two products equally: not clear');
        $this->assertNull($match(null, 'kaosnya ready?') ?? null, 'whole words only');
    }

    public function test_a_new_lead_gets_its_product_and_the_reason_and_later_messages_do_not_guess_again(): void
    {
        [$raket, $tas] = $this->catalog();
        $gw = $this->gateway(AiMode::Off);

        $this->inbound($gw, 'Halo, tas selempang yang hitam ready?');
        $lead = $this->lead();
        $this->assertSame([$tas->getKey(), 'keyword'], [$lead->property_id, $lead->product_source]);
        $this->assertStringContainsString('Produk diminati: Tas Selempang Sport (ditentukan dari kata kunci di pesan pertama: "selempang")',
            $this->in(fn () => $lead->activities()->where('type', 'note')->latest('id')->value('body')));

        $this->inbound($gw, 'kalau raket capra ada?');
        $this->assertSame($tas->getKey(), $this->lead()->property_id, 'only the first message is read for keywords; the AI follows a switch');

        // A new ad click on a returning lead moves it, unless an agent picked the product.
        $this->inbound($gw, 'halo', ['source_id' => '120210000000001', 'headline' => 'Raket']);
        $this->assertSame([$raket->getKey(), 'ad_id'], [$this->lead()->property_id, $this->lead()->product_source]);
        $this->in(fn () => ProductMatcher::set($this->lead(), $tas, 'agent'));
        $this->inbound($gw, 'halo lagi', ['source_id' => '120210000000001', 'headline' => 'Raket']);
        $this->assertSame([$tas->getKey(), 'agent'], [$this->lead()->property_id, $this->lead()->product_source]);
    }

    // ---- 3–5. What the AI is told ---------------------------------------------------------------------------------

    public function test_the_ai_gets_the_leads_product_with_its_catalogue_details_and_only_its_knowledge(): void
    {
        [$raket, $tas] = $this->catalog();
        $this->in(function () use ($raket, $tas) {
            AiKnowledgeItem::create(['title' => 'Jam buka', 'content' => 'Toko buka jam 9 sampai 17.']);
            AiKnowledgeItem::create(['title' => 'Cara pasang senar', 'content' => 'Senar raket dipasang 24 lbs.', 'property_id' => $raket->getKey()]);
            AiKnowledgeItem::create(['title' => 'Bahan tas', 'content' => 'Tas dari kanvas anti air.', 'property_id' => $tas->getKey()]);
        });

        $draft = $this->in(fn () => AiDraft::where('message_id', $this->inbound($this->gateway(), 'Halo kak, raket capra berapa?')->getKey())->first());
        $this->assertSame(AiDraft::PENDING, $draft->status);

        $system = $this->lastSystem();
        $this->assertStringContainsString("Pelanggan ini menanyakan produk: Raket Capra Pro (id {$raket->getKey()})", $system);
        $this->assertStringContainsString("Harga normal (dicoret): Rp 499.000\nHarga promo: Rp 349.000", $system);
        $this->assertStringContainsString('Promo: Diskon 30% minggu ini', $system);
        $this->assertStringContainsString('Varian & rincian: Warna: Merah, Biru', $system);
        $this->assertStringContainsString('Stok: Tersedia', $system);
        $this->assertStringContainsString('Berat: 400 g', $system);
        $this->assertStringContainsString('Toko buka jam 9', $system, 'general knowledge is always there');
        $this->assertStringContainsString('Senar raket dipasang 24 lbs', $system, 'the product\'s own knowledge');
        $this->assertStringNotContainsString('kanvas anti air', $system, 'another product\'s knowledge never mixes in');

        $this->assertSame('Toko buka jam 9 sampai 17.', trim(str_replace('Jam buka:', '', $this->in(fn () => app(KnowledgeBase::class)->context('jam buka')))), 'no product: general only');
    }

    public function test_the_ai_asks_which_product_when_it_is_not_clear_and_follows_when_the_buyer_names_one(): void
    {
        [$raket, $tas] = $this->catalog();
        $this->in(fn () => AiKnowledgeItem::create(['title' => 'Bahan tas', 'content' => 'Tas dari kanvas anti air.', 'property_id' => $tas->getKey()]));
        $gw = $this->gateway(AiMode::Auto);

        $this->aiAnswer = 'Halo Kak, mau tanya produk yang mana? Raket Capra Pro, Tas Selempang Sport, atau Kaos Lari?';
        $this->inbound($gw, 'Halo kak, masih ada?');
        $system = $this->lastSystem();
        $this->assertStringContainsString('PRODUK PELANGGAN: belum diketahui produk mana', $system);
        $this->assertStringContainsString('tanyakan dulu dengan sopan produk mana yang dimaksud, sebutkan pilihannya: Kaos Lari, Raket Capra Pro, Tas Selempang Sport', $system);
        $this->assertStringContainsString("DAFTAR PRODUK (id | nama):\n", $system);
        $this->assertStringNotContainsString('kanvas anti air', $system);
        $this->assertNull($this->lead()->property_id);

        // The buyer answers; the AI marks the product, the marker never reaches the buyer, and the lead follows.
        $this->aiAnswer = "Tas Selempang Sport harganya Rp 129.000, Kak.\nPRODUK: {$tas->getKey()}, 999999";
        $this->inbound($gw, 'yang tas');
        $sent = $this->in(fn () => WaMessage::query()->where('direction', 'out')->latest('id')->value('body'));
        $this->assertSame('Tas Selempang Sport harganya Rp 129.000, Kak.', $sent);
        $this->assertSame([$tas->getKey(), 'ai'], [$this->lead()->property_id, $this->lead()->product_source]);

        // From now on the AI answers about the bag, with the bag's knowledge.
        $this->aiAnswer = 'Bahannya kanvas, Kak.';
        $this->inbound($gw, 'bahannya apa?');
        $this->assertStringContainsString('Pelanggan ini menanyakan produk: Tas Selempang Sport', $this->lastSystem());
        $this->assertStringContainsString('kanvas anti air', $this->lastSystem());

        // Switching to two products: the first becomes the lead's, the other is noted.
        $this->aiAnswer = "Bisa, Kak.\nPRODUK: {$raket->getKey()}, {$tas->getKey()}";
        $this->inbound($gw, 'sekalian raketnya juga deh');
        $this->assertSame($raket->getKey(), $this->lead()->property_id);
        $this->assertStringContainsString('juga tertarik: Tas Selempang Sport', $this->in(fn () => $this->lead()->activities()->where('type', 'note')->latest('id')->value('body')));
    }

    public function test_a_single_product_catalogue_needs_no_question(): void
    {
        $this->in(fn () => Property::create(['name' => 'Raket Capra Pro', 'price_from' => 349000]));
        $this->inbound($this->gateway(), 'Halo');

        $system = $this->lastSystem();
        $this->assertStringContainsString('Pelanggan ini menanyakan produk: Raket Capra Pro', $system);
        $this->assertStringContainsString('Harga: Rp 349.000', $system);
        $this->assertStringNotContainsString('belum diketahui', $system);
        $this->assertStringNotContainsString('DAFTAR PRODUK', $system);
    }

    // ---- 6. Agents change it -------------------------------------------------------------------------------------

    public function test_an_agent_changes_the_product_in_the_inbox_and_the_ai_follows(): void
    {
        [$raket, $tas] = $this->catalog();
        $gw = $this->gateway();
        $this->inbound($gw, 'mau raket capra');
        $lead = $this->lead();

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(Inbox::class, ['leadId' => $lead->getKey()])->assertSee('Raket Capra Pro')
            ->callAction(TestAction::make('product'), ['property_id' => $tas->getKey()])->assertHasNoActionErrors();

        $this->assertSame([$tas->getKey(), 'agent'], [$this->lead()->property_id, $this->lead()->product_source]);

        $this->inbound($gw, 'harganya?');
        $this->assertStringContainsString('Pelanggan ini menanyakan produk: Tas Selempang Sport', $this->lastSystem());

        // The lead page marks a change there as the agent's too.
        Livewire::test(EditLead::class, ['record' => $lead->getKey()])->fillForm(['property_id' => $raket->getKey()])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$raket->getKey(), 'agent'], [$this->lead()->property_id, $this->lead()->product_source]);
    }
}
