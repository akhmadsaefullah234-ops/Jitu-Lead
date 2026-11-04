<?php

namespace Tests\Feature;

use App\Actions\RunFollowUps;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Resources\AiKnowledge\Pages\ManageAiKnowledge;
use App\Filament\Resources\AiSuggestions\Pages\ManageAiSuggestions;
use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use App\Filament\Resources\FollowUpRules\Pages\ManageFollowUpRules;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\ManageProperties;
use App\Filament\Resources\Properties\RelationManagers\FollowUpsRelationManager;
use App\Filament\Resources\Properties\RelationManagers\KnowledgeRelationManager;
use App\Models\AiKnowledgeItem;
use App\Models\AiSuggestion;
use App\Models\FollowUpLog;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Privacy\TenantExport;
use App\Support\CurrentTenant;
use App\WhatsApp\GatewayUrlGuard;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Everything that only concerns one product is set on that product's page: its AI knowledge and its own follow-up
 * sequence, which replaces the general one for the stages it covers. The general menus hold only what applies to
 * every product.
 */
class ProductSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Property $capra;

    private Property $yonex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->tenant->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
        GatewayUrlGuard::resolveUsing(fn (string $host) => $host === 'gw.example.com' ? ['93.184.216.34'] : []);
        // Starts without the starter follow-ups, so each test sees only its own rules.
        $this->inTenant(fn () => FollowUpRule::query()->delete());
        $n = 0;
        Http::fake(['gw.example.com/*' => function () use (&$n) {
            return Http::response(['id' => 'm'.++$n]);
        }]);

        [$this->capra, $this->yonex] = $this->inTenant(fn () => [
            Property::create(['name' => 'CAPRA Pro Raket', 'price_from' => 349000]),
            Property::create(['name' => 'Yonex Astrox', 'price_from' => 1200000]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        GatewayUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function admin(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
    }

    private function gateway(): void
    {
        $this->inTenant(fn () => WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'GW', 'phone' => '+6281100000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's'],
        ]));
    }

    private function rule(string $name, int $days, ?Property $product = null, string $stage = 'Lead baru', bool $active = true): FollowUpRule
    {
        return $this->inTenant(fn () => FollowUpRule::create([
            'name' => $name, 'stage_id' => $this->stage($this->tenant, $stage)->getKey(), 'property_id' => $product?->getKey(),
            'delay_days' => $days, 'send_time' => '09:00', 'body' => "$name {produk}", 'active' => $active,
        ]));
    }

    private function lead(string $enteredAt, ?Property $product = null): Lead
    {
        $lead = $this->makeLead($this->tenant, ['name' => 'Budi Santoso', 'property_id' => $product?->getKey()]);
        $lead->forceFill(['stage_entered_at' => Carbon::parse($enteredAt, 'Asia/Jakarta')])->save();

        return $lead;
    }

    /** @return list<string> the names of the rules sent to the lead, oldest first */
    private function sent(Lead $lead): array
    {
        return $this->inTenant(fn () => FollowUpLog::query()->where('lead_id', $lead->getKey())->where('status', 'sent')->orderBy('id')
            ->get()->map(fn (FollowUpLog $l) => FollowUpRule::find($l->follow_up_rule_id)->name)->all());
    }

    private function runFollowUps(): void
    {
        app(RunFollowUps::class)($this->tenant);
    }

    private function manager(string $class, Property $product)
    {
        return Livewire::test($class, ['ownerRecord' => $product, 'pageClass' => EditProperty::class]);
    }

    // --- which sequence a lead gets ---

    public function test_a_capra_lead_gets_the_capra_sequence_and_a_lead_without_a_product_the_general_one(): void
    {
        $this->gateway();
        $this->rule('Umum H+1', 1);
        $this->rule('CAPRA H+1', 1, $this->capra);
        $this->rule('CAPRA nonaktif H+1', 1, $this->yonex, active: false);
        $capra = $this->lead('2026-10-11 12:00', $this->capra);
        $none = $this->lead('2026-10-11 12:00');
        $yonex = $this->lead('2026-10-11 12:00', $this->yonex);

        $this->runFollowUps();
        $this->runFollowUps();

        $this->assertSame(['CAPRA H+1'], $this->sent($capra), 'only the product sequence, never the general one too');
        $this->assertSame(['Umum H+1'], $this->sent($none));
        $this->assertSame(['Umum H+1'], $this->sent($yonex), 'a product whose rules are all off falls back to the general sequence');
        $this->assertStringContainsString('CAPRA Pro Raket', $this->inTenant(fn () => $capra->conversation()->first()->messages()->where('is_intro', false)->first()->body));
    }

    public function test_a_product_sequence_for_one_stage_leaves_the_other_stages_on_the_general_one(): void
    {
        $this->gateway();
        $this->rule('Umum H+1', 1);
        $this->rule('CAPRA kunjungan H+1', 1, $this->capra, 'Dihubungi');
        $lead = $this->lead('2026-10-11 12:00', $this->capra);

        $this->runFollowUps();

        $this->assertSame(['Umum H+1'], $this->sent($lead));
    }

    public function test_changing_the_product_mid_sequence_continues_with_the_new_one_without_going_back_or_sending_twice(): void
    {
        $this->gateway();
        $this->rule('Umum H+1', 1);
        $this->rule('Umum H+3', 3);
        $this->rule('CAPRA H+1', 1, $this->capra);
        $this->rule('CAPRA H+2', 2, $this->capra);
        $this->rule('CAPRA H+3', 3, $this->capra);
        $lead = $this->lead('2026-10-11 12:00');

        $this->runFollowUps();
        $this->assertSame(['Umum H+1'], $this->sent($lead));

        // The client turns out to ask about CAPRA; the next morning CAPRA H+1 is also "due" but that step is done.
        $this->inTenant(fn () => $lead->update(['property_id' => $this->capra->getKey()]));
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00', 'Asia/Jakarta'));
        $this->runFollowUps();
        $this->assertSame(['Umum H+1', 'CAPRA H+2'], $this->sent($lead));

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
        $this->runFollowUps();
        $this->runFollowUps();
        $this->assertSame(['Umum H+1', 'CAPRA H+2', 'CAPRA H+3'], $this->sent($lead), 'the general H+3 is not sent next to CAPRA H+3');
    }

    // --- the product page ---

    public function test_the_knowledge_tab_shows_only_this_products_items_and_what_is_added_there_belongs_to_it(): void
    {
        [$general, $own, $other] = $this->inTenant(fn () => [
            AiKnowledgeItem::create(['title' => 'Alamat toko', 'content' => 'Jl. Melati 3']),
            AiKnowledgeItem::create(['title' => 'Garansi CAPRA', 'content' => '1 tahun', 'property_id' => $this->capra->getKey()]),
            AiKnowledgeItem::create(['title' => 'Garansi Yonex', 'content' => '2 tahun', 'property_id' => $this->yonex->getKey()]),
        ]);
        $this->admin();

        $tab = $this->manager(KnowledgeRelationManager::class, $this->capra)
            ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$general, $other])
            ->callAction(TestAction::make('create')->table(), ['title' => 'Senar bawaan', 'content' => 'BG65, 24 lbs', 'active' => true])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('upload')->table(), ['file' => UploadedFile::fake()->createWithContent('harga.txt', 'CAPRA Pro diskon 10 persen minggu ini.')])
            ->assertHasNoActionErrors();

        $this->assertSame(3, $this->inTenant(fn () => AiKnowledgeItem::where('property_id', $this->capra->getKey())->count()));
        $this->assertSame('3', KnowledgeRelationManager::getBadge($this->capra, EditProperty::class));
        $this->inTenant(fn () => $own->update(['active' => false]));
        $this->assertSame('2', KnowledgeRelationManager::getBadge($this->capra, EditProperty::class), 'the badge counts active items');
        $tab->assertOk();
    }

    public function test_the_follow_up_tab_numbers_the_steps_per_stage_and_copies_arrive_switched_off(): void
    {
        $this->rule('Umum H+1', 1);
        $this->rule('Umum H+3', 3);
        $this->rule('Yonex H+2', 2, $this->yonex);
        $this->rule('CAPRA H+1', 1, $this->capra);
        $this->admin();

        $this->manager(FollowUpsRelationManager::class, $this->capra)
            ->assertSee('Lead baru 1/1')->assertDontSee('Umum H+1')->assertDontSee('Yonex H+2')
            ->callAction(TestAction::make('copyGeneral')->table())
            ->assertNotified('2 langkah disalin, masih nonaktif')
            ->callAction(TestAction::make('copyProduct')->table(), ['from' => $this->yonex->getKey()])
            ->assertSee('Lead baru 4/4');

        $copies = $this->inTenant(fn () => FollowUpRule::where('property_id', $this->capra->getKey())->where('name', '!=', 'CAPRA H+1')->get());
        $this->assertEqualsCanonicalizing(['Umum H+1', 'Umum H+3', 'Yonex H+2'], $copies->pluck('name')->all());
        $this->assertTrue($copies->every(fn (FollowUpRule $r) => $r->active === false));
        $this->assertSame(1, $this->inTenant(fn () => FollowUpRule::where('property_id', $this->yonex->getKey())->count()), 'the source is left as it was');
    }

    public function test_a_step_added_on_the_product_page_belongs_to_it_and_the_preview_names_it(): void
    {
        $this->admin();

        $this->manager(FollowUpsRelationManager::class, $this->capra)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Tawaran', 'stage_id' => $this->stage($this->tenant, 'Lead baru')->getKey(), 'delay_days' => 2, 'send_time' => '10:00', 'body' => 'Halo {nama}, {produk} tinggal 3.'])
            ->assertHasNoActionErrors()
            ->assertSee('Halo Rina, CAPRA Pro Raket tinggal 3.');

        $this->assertSame('Pratinjau: Halo Rina, CAPRA Pro Raket tinggal 3.', $this->inTenant(fn () => FollowUpRuleResource::preview('Halo {nama}, {produk} tinggal 3.', $this->capra)));
        $this->assertSame($this->capra->getKey(), $this->inTenant(fn () => FollowUpRule::where('name', 'Tawaran')->value('property_id')));
    }

    public function test_the_plan_limit_counts_general_and_product_follow_ups_together(): void
    {
        $this->tenant->currentSubscription()->update(['plan' => 'mandiri', 'status' => 'active', 'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->rule('Umum H+1', 1);
        $this->rule('CAPRA H+1', 1, $this->capra);
        $this->admin();

        $this->manager(FollowUpsRelationManager::class, $this->yonex)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Baru', 'delay_days' => 2, 'send_time' => '09:00', 'body' => 'Halo', 'active' => true])
            ->assertNotified('Batas paket tercapai');
        $this->assertSame(2, $this->inTenant(fn () => FollowUpRule::count()));
    }

    public function test_details_and_ads_are_separate_tabs_of_one_product_page(): void
    {
        $this->inTenant(fn () => $this->capra->update(['promo_text' => 'Gratis grip', 'ad_keywords' => ['capra']]));
        $this->admin();

        $page = Livewire::test(EditProperty::class, ['record' => $this->capra->getKey()])
            ->assertSee(['Detail properti', 'Pengetahuan AI', 'Follow-up', 'Iklan & promo'])
            ->assertFormFieldVisible('name')->assertFormFieldHidden('promo_text')
            ->fillForm(['name' => 'CAPRA Pro Raket 3U'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Gratis grip', $this->capra->fresh()->promo_text, 'saving the details keeps the promo');

        $page->set('activeRelationManager', EditProperty::PROMO)
            ->assertFormFieldHidden('name')->assertFormFieldVisible('promo_text')
            ->fillForm(['promo_text' => 'Diskon 10%', 'compare_price' => 399000])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Diskon 10%', $this->capra->fresh()->promo_text);
        $this->assertSame('CAPRA Pro Raket 3U', $this->capra->fresh()->name);

        $page->fillForm(['compare_price' => 100])->call('save')->assertHasFormErrors(['compare_price']);
    }

    public function test_the_product_list_counts_knowledge_and_active_follow_ups(): void
    {
        $this->inTenant(fn () => AiKnowledgeItem::create(['title' => 'Garansi', 'content' => '1 tahun', 'property_id' => $this->capra->getKey()]));
        $this->rule('CAPRA H+1', 1, $this->capra);
        $this->rule('CAPRA H+2', 2, $this->capra, active: false);
        $this->admin();

        Livewire::test(ManageProperties::class)
            ->assertTableColumnStateSet('knowledge_count', 1, $this->capra)
            ->assertTableColumnStateSet('follow_ups_count', 1, $this->capra)
            ->assertTableColumnStateSet('follow_ups_count', 0, $this->yonex);
    }

    // --- the general menus ---

    public function test_the_general_knowledge_menu_lists_only_general_items_and_shows_product_items_read_only_on_request(): void
    {
        [$general, $own] = $this->inTenant(fn () => [
            AiKnowledgeItem::create(['title' => 'Alamat toko', 'content' => 'Jl. Melati 3']),
            AiKnowledgeItem::create(['title' => 'Garansi CAPRA', 'content' => '1 tahun', 'property_id' => $this->capra->getKey()]),
        ]);
        $this->admin();

        Livewire::test(ManageAiKnowledge::class)
            ->assertSee('Pengetahuan umum (semua properti)')
            ->assertCanSeeTableRecords([$general])->assertCanNotSeeTableRecords([$own])
            ->assertFormFieldDoesNotExist('property_id')
            ->callAction('create', ['title' => 'Jam buka', 'content' => '09.00-17.00', 'active' => true])
            ->filterTable('with_products')
            ->assertCanSeeTableRecords([$general, $own])
            ->assertActionHidden(TestAction::make('edit')->table($own))
            ->assertActionVisible(TestAction::make('openProduct')->table($own))
            ->assertActionVisible(TestAction::make('edit')->table($general));

        $this->assertNull($this->inTenant(fn () => AiKnowledgeItem::where('title', 'Jam buka')->value('property_id')));
    }

    public function test_the_general_follow_up_menu_lists_only_general_rules_and_links_to_products(): void
    {
        $general = $this->rule('Umum H+1', 1);
        $own = $this->rule('CAPRA H+1', 1, $this->capra);
        $this->admin();

        Livewire::test(ManageFollowUpRules::class)
            ->assertSee('Follow-up umum (semua properti)')
            ->assertCanSeeTableRecords([$general])->assertCanNotSeeTableRecords([$own])
            ->assertSee('CAPRA Pro Raket (1 aktif)');
    }

    public function test_an_approved_learned_answer_goes_to_the_conversations_product_unless_another_is_picked(): void
    {
        $lead = $this->lead('2026-10-11 12:00', $this->capra);
        [$a, $b] = $this->inTenant(fn () => [
            AiSuggestion::create(['lead_id' => $lead->getKey(), 'question' => 'Garansi berapa lama?', 'answer' => '1 tahun', 'status' => 'pending']),
            AiSuggestion::create(['lead_id' => $lead->getKey(), 'question' => 'Bisa COD?', 'answer' => 'Bisa', 'status' => 'pending']),
        ]);
        $this->admin();

        Livewire::test(ManageAiSuggestions::class)
            ->mountAction(TestAction::make('approve')->table($a))->assertActionDataSet(['property_id' => $this->capra->getKey()])
            ->callMountedAction()
            ->callAction(TestAction::make('approve')->table($b), ['property_id' => null]);

        $this->assertSame($this->capra->getKey(), $this->inTenant(fn () => AiKnowledgeItem::where('question', 'Garansi berapa lama?')->value('property_id')));
        $this->assertNull($this->inTenant(fn () => AiKnowledgeItem::where('question', 'Bisa COD?')->value('property_id')));
    }

    public function test_the_tenant_export_says_which_product_a_follow_up_belongs_to(): void
    {
        $this->rule('CAPRA H+1', 1, $this->capra);

        $zip = new ZipArchive;
        $zip->open($path = app(TenantExport::class)($this->tenant));
        $csv = $zip->getFromName('aturan-follow-up.csv');
        $knowledge = $zip->getFromName('pengetahuan-ai.csv');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('property_id', strtok($csv, "\n"));
        $this->assertStringContainsString('property_id', strtok($knowledge, "\n"));
    }
}
