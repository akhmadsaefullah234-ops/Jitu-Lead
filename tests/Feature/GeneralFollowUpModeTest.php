<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Actions\RunFollowUps;
use App\Billing\PlanLimits;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\FollowUpRules\Pages\ManageFollowUpRules;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\RelationManagers\FollowUpsRelationManager;
use App\FollowUps\AskProduct;
use App\FollowUps\GeneralFollowUps;
use App\LeadForms\FormFields;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A shop with more than one product follows up per product: the general rules are skipped (not changed) and a
 * lead without a product gets one "Tanya produk" message listing the products, whose numbered reply picks the
 * product. A property agency and a one-product shop keep the general follow-ups; the owner can force either way.
 */
class GeneralFollowUpModeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private WaChannel $gw;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 11:00:00', 'Asia/Jakarta'));
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        $n = 0;
        Http::fake(['gw.example.com/*' => function () use (&$n) {
            return Http::response(['id' => 'm'.++$n]);
        }]);

        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->shop->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $this->gw = $this->gateway($this->shop);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $fn);
    }

    private function gateway(Tenant $tenant): WaChannel
    {
        return $this->in($tenant, fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    /** @return list<Property> */
    private function products(int $count = 2): array
    {
        $all = [
            ['name' => 'CAPRA Pro Raket', 'price_from' => 349000, 'compare_price' => 499000],
            ['name' => 'Yonex Astrox', 'price_from' => 1200000],
        ];

        return $this->in($this->shop, fn () => array_map(fn ($p) => Property::create($p), array_slice($all, 0, $count)));
    }

    private function rule(Tenant $tenant, string $name, ?Property $product = null): FollowUpRule
    {
        return $this->in($tenant, fn () => FollowUpRule::create([
            'name' => $name, 'stage_id' => $this->firstStage($tenant), 'property_id' => $product?->getKey(),
            'delay_days' => 1, 'send_time' => '09:00', 'body' => "$name {produk}", 'active' => true,
        ]));
    }

    private function firstStage(Tenant $tenant): int
    {
        return $this->in($tenant, fn () => Stage::query()->where('type', 'open')->orderBy('position')->value('id'));
    }

    private function lead(Tenant $tenant, ?Property $product = null, string $phone = '+6281234560001'): Lead
    {
        $lead = $this->in($tenant, fn () => Lead::create([
            'name' => 'Rina Kartika', 'phone' => $phone, 'stage_id' => $this->firstStage($tenant), 'property_id' => $product?->getKey(),
        ]));
        $lead->forceFill(['stage_entered_at' => Carbon::parse('2026-10-11 08:00', 'Asia/Jakarta')])->save();

        return $lead;
    }

    /** @return list<string> */
    private function sent(Tenant $tenant, Lead $lead): array
    {
        return $this->in($tenant, fn () => FollowUpLog::query()->where('lead_id', $lead->getKey())->where('status', 'sent')->orderBy('id')
            ->get()->map(fn (FollowUpLog $l) => FollowUpRule::find($l->follow_up_rule_id)->name)->all());
    }

    private function lastText(Tenant $tenant, Lead $lead): string
    {
        return $this->in($tenant, fn () => WaConversation::where('lead_id', $lead->getKey())->first()->messages()->where('is_intro', false)->latest('id')->value('body'));
    }

    private function runFollowUps(Tenant $tenant): void
    {
        app(RunFollowUps::class)($tenant);
    }

    public function test_a_shop_with_two_products_skips_the_general_rules_and_asks_a_lead_without_a_product_once(): void
    {
        [$capra] = $this->products();
        $this->rule($this->shop, 'Umum H+1');
        $this->rule($this->shop, 'CAPRA H+1', $capra);
        $withProduct = $this->lead($this->shop, $capra);
        $without = $this->lead($this->shop, phone: '+6281234560002');

        $this->runFollowUps($this->shop);
        $this->runFollowUps($this->shop);
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00', 'Asia/Jakarta'));
        $this->runFollowUps($this->shop);

        $this->assertFalse($this->in($this->shop, fn () => GeneralFollowUps::for($this->shop)->applies()));
        $this->assertSame(['CAPRA H+1'], $this->sent($this->shop, $withProduct));
        $this->assertSame(['Tanya produk'], $this->sent($this->shop, $without), 'sent once, never the general rule');
        $this->assertStringContainsString("1. CAPRA Pro Raket Rp349.000 (dari ~Rp499.000~)\n2. Yonex Astrox Rp1.200.000", $this->lastText($this->shop, $without));
        $this->assertStringStartsWith('Halo kak Rina 😊 Kemarin kakak sempat tanya produk Quicker Sport ya.', $this->lastText($this->shop, $without));
        $this->assertTrue($this->in($this->shop, fn () => FollowUpRule::where('name', 'Umum H+1')->value('active')), 'the general rule is left as it was');
    }

    public function test_a_numbered_reply_picks_the_product_and_its_sequence_continues_without_going_back(): void
    {
        [$capra, $yonex] = $this->products();
        $this->rule($this->shop, 'Yonex H+1', $yonex);
        $this->in($this->shop, fn () => FollowUpRule::create(['name' => 'Yonex H+2', 'stage_id' => $this->firstStage($this->shop), 'property_id' => $yonex->getKey(),
            'delay_days' => 2, 'send_time' => '09:00', 'body' => 'Yonex H+2', 'active' => true]));
        $lead = $this->lead($this->shop, phone: '+6281299990000');
        $this->runFollowUps($this->shop);
        $this->assertSame([$capra->getKey(), $yonex->getKey()], $this->in($this->shop, fn () => FollowUpLog::where('lead_id', $lead->getKey())->value('product_ids')));

        Carbon::setTestNow(Carbon::parse('2026-10-12 11:30:00', 'Asia/Jakarta'));
        $body = json_encode(['event' => 'message', 'id' => 'g1', 'from' => '6281299990000', 'type' => 'text', 'text' => '2 kak', 'timestamp' => now()->timestamp]);
        $this->call('POST', "/webhooks/whatsapp/gateway/{$this->gw->webhook_token}", [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json', 'X-Gateway-Signature' => 'sha256='.hash_hmac('sha256', $body, 's'),
        ]), $body)->assertOk();

        $lead->refresh();
        $this->assertSame($yonex->getKey(), $lead->property_id, '"2" is the second product of the list');
        $this->assertSame('ask_reply', $lead->product_source);

        // Yonex H+1 (09:00) is before the question that was sent at 10:00, so only H+2 follows; the answer "2" itself
        // does not count as the lead writing back.
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:30:00', 'Asia/Jakarta'));
        $this->runFollowUps($this->shop);
        $this->assertSame(['Tanya produk', 'Yonex H+2'], $this->sent($this->shop, $lead));
    }

    public function test_only_a_number_that_was_listed_maps(): void
    {
        $this->products();
        $lead = $this->lead($this->shop);
        $this->runFollowUps($this->shop);

        $this->in($this->shop, function () use ($lead) {
            $this->assertNull(AskProduct::answer($lead, '5'));
            $this->assertNull(AskProduct::answer($lead, 'harganya 349000 ya?'));
            $this->assertSame('CAPRA Pro Raket', AskProduct::answer($lead, 'no 1')?->name);
        });
    }

    public function test_a_one_product_shop_and_a_property_agency_keep_the_general_follow_ups(): void
    {
        $this->products(1);
        $this->rule($this->shop, 'Umum H+1');
        $shopLead = $this->lead($this->shop);

        $agency = $this->makeTenant('griya-uji');
        $agency->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $this->gateway($agency);
        $this->in($agency, fn () => array_map(fn ($n) => Property::create(['name' => $n]), ['Cluster A', 'Cluster B', 'Cluster C']));
        $this->rule($agency, 'Umum properti');
        $agencyLead = $this->lead($agency, phone: '+6281234569999');

        $this->runFollowUps($this->shop);
        $this->runFollowUps($agency);

        $this->assertSame(['Umum H+1'], $this->sent($this->shop, $shopLead));
        $this->assertSame(['Umum properti'], $this->sent($agency, $agencyLead));
        $this->assertSame(0, $this->in($this->shop, fn () => FollowUpRule::where('kind', AskProduct::KIND)->count()), 'no question while the general rules run');
    }

    public function test_the_manual_choice_wins_over_the_product_count(): void
    {
        $this->products();
        $this->rule($this->shop, 'Umum H+1');
        $this->shop->update(['general_follow_ups' => 'always']);
        $lead = $this->lead($this->shop);
        $this->runFollowUps($this->shop);
        $this->assertSame(['Umum H+1'], $this->sent($this->shop, $lead));

        $agency = $this->makeTenant('griya-manual');
        $agency->forceFill(['timezone' => 'Asia/Jakarta', 'general_follow_ups' => 'never'])->save();
        $this->gateway($agency);
        $this->in($agency, fn () => Property::create(['name' => 'Cluster A']));
        $this->rule($agency, 'Umum properti');
        $agencyLead = $this->lead($agency, phone: '+6281234569999');
        $this->runFollowUps($agency);
        $this->assertSame(['Tanya produk'], $this->sent($agency, $agencyLead));
    }

    public function test_the_follow_up_menu_shows_why_and_saves_the_choice(): void
    {
        $this->products();
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(ManageFollowUpRules::class)
            ->assertSee('Follow-up umum: tidak dipakai')
            ->assertSee('Tidak dipakai: toko retail dengan 2 produk aktif. Atur follow-up di halaman tiap produk.')
            ->assertSee('Pesan cadangan "Tanya produk"')
            ->assertSee('Aturan di bawah sedang tidak dikirim')
            ->callAction('askProduct', ['name' => 'Tanya produk', 'stage_id' => $this->firstStage($this->shop), 'delay_days' => 2, 'send_time' => '19:00', 'body' => 'Mau yang mana kak? {daftar_produk}', 'active' => false])
            ->assertHasNoActionErrors()
            ->callAction('generalMode', ['general_follow_ups' => 'always'])
            ->assertHasNoActionErrors();

        $rule = $this->in($this->shop, fn () => AskProduct::rule());
        $this->assertSame([2, '19:00', false], [$rule->delay_days, $rule->send_time, $rule->active]);
        $this->assertSame('always', $this->shop->fresh()->general_follow_ups);
        $this->assertSame($this->in($this->shop, fn () => FollowUpRule::whereNull('kind')->count()), $this->in($this->shop, fn () => PlanLimits::for($this->shop)->usage('followups')), 'the built-in question does not use the plan');
    }

    public function test_the_product_tab_warns_when_the_product_would_get_no_follow_up(): void
    {
        [$capra, $yonex] = $this->products();
        $this->rule($this->shop, 'CAPRA H+1', $capra);
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        Livewire::test(FollowUpsRelationManager::class, ['ownerRecord' => $yonex, 'pageClass' => EditProperty::class])
            ->assertSee('Lead produk ini belum akan menerima follow-up');
        Livewire::test(FollowUpsRelationManager::class, ['ownerRecord' => $capra, 'pageClass' => EditProperty::class])
            ->assertDontSee('Lead produk ini belum akan menerima follow-up');
    }

    public function test_a_form_with_a_product_gives_its_leads_that_product_and_a_matching_answer_wins(): void
    {
        [$capra, $yonex] = $this->products();
        $this->in($this->shop, fn () => LeadForm::create([
            'name' => 'Order CAPRA', 'slug' => 'order', 'status' => 'active', 'design' => ['title' => 'Order'], 'property_id' => $capra->getKey(),
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'raket', 'type' => 'select', 'label' => 'Raket', 'options' => ['Yonex Astrox', 'Lainnya'], 'maps_to' => 'product'],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));

        $this->postJson('/f/quicker-sport/order', ['f' => ['nama' => 'Rina', 'wa' => '081234567890', 'raket' => 'Lainnya']])->assertOk();
        $this->postJson('/f/quicker-sport/order', ['f' => ['nama' => 'Sari', 'wa' => '081234567891', 'raket' => 'Yonex Astrox']])->assertOk();

        $rina = $this->in($this->shop, fn () => Lead::where('phone', '+6281234567890')->first());
        $sari = $this->in($this->shop, fn () => Lead::where('phone', '+6281234567891')->first());
        $this->assertSame([$capra->getKey(), 'form'], [$rina->property_id, $rina->product_source]);
        $this->assertSame([$yonex->getKey(), 'form'], [$sari->property_id, $sari->product_source]);
    }
}
