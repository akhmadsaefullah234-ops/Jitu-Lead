<?php

namespace Tests\Feature;

use App\Actions\DemoData;
use App\Filament\Pages\Tenancy\RegisterAgency;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Properties\Pages\ManageProperties;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LostReason;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Retention\TrialEmails;
use App\Support\Industry;
use App\Support\IndustryTemplate;
use App\WhatsApp\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** An agency picks its kind of business when it signs up and gets that industry's pipeline, fields and words. */
class IndustryTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY_FIELDS = ['need', 'property_type', 'location', 'payment_method', 'budget_min', 'budget_max', 'property_id'];

    private const SHOP_FIELDS = ['property_id', 'variant', 'quantity', 'shipping_address', 'courier'];

    private function register(string $name, string $slug, ?string $industry): Tenant
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(RegisterAgency::class)
            ->fillForm(array_filter(['name' => $name, 'slug' => $slug, 'industry' => $industry]))
            ->call('register')->assertHasNoFormErrors();

        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $this->actingInTenant($user, $tenant);

        return $tenant;
    }

    /** @return list<string> */
    private function stages(): array
    {
        return Stage::query()->orderBy('position')->pluck('name')->all();
    }

    public function test_the_signup_form_asks_for_the_kind_of_business(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(RegisterAgency::class)->assertSee('Jenis bisnis')->assertSee('Retail / toko online')->assertSee('Properti');
    }

    public function test_a_property_agency_gets_the_property_template(): void
    {
        $tenant = $this->register('Griya Baru', 'griya-baru', 'properti');

        $this->assertSame('properti', $tenant->industry);
        $this->assertSame(['Lead baru', 'Dihubungi', 'Terkualifikasi', 'Jadwal survei', 'Sudah survei', 'Negosiasi', 'Booking', 'Closing', 'Gugur'], $this->stages());
        $this->assertTrue(LostReason::query()->where('label', 'KPR ditolak')->exists());
        $this->assertTrue(LeadSource::query()->where('name', 'Portal properti')->exists());
        $this->assertStringContainsString('jadwal survei', $tenant->fresh()->ai_instructions);

        $page = Livewire::test(CreateLead::class)->assertSee('Kebutuhan properti');
        foreach (self::PROPERTY_FIELDS as $field) {
            $page->assertFormFieldVisible($field);
        }
        foreach (['variant', 'quantity', 'shipping_address', 'courier'] as $field) {
            $page->assertFormFieldHidden($field);
        }

        $this->assertSame('Properti', PropertyResource::getNavigationLabel());
    }

    public function test_a_shop_gets_the_retail_template(): void
    {
        $tenant = $this->register('Toko Kaos', 'toko-kaos', 'retail');

        $this->assertSame('retail', $tenant->industry);
        $this->assertSame(['Tanya produk', 'Order masuk', 'Menunggu pembayaran', 'Dikemas & dikirim', 'Selesai', 'Batal'], $this->stages());
        $this->assertSame('won', Stage::query()->where('name', 'Selesai')->first()->type->value);
        $this->assertSame('lost', Stage::query()->where('name', 'Batal')->first()->type->value);
        $this->assertEqualsCanonicalizing(
            ['Ongkir mahal', 'Stok/ukuran habis', 'Tidak ada respons', 'Beli di marketplace', 'Hanya tanya', 'Batal setelah order'],
            LostReason::query()->pluck('label')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Iklan Meta', 'Instagram', 'TikTok', 'Marketplace', 'WhatsApp', 'Formulir web', 'Referral'],
            LeadSource::query()->pluck('name')->all(),
        );
        $this->assertFalse($tenant->followUpRules()->where('active', true)->exists(), 'Follow-ups start switched off');
        $this->assertStringContainsString('{produk}', $tenant->followUpRules()->first()->body);
        $this->assertStringContainsString('kurir', $tenant->fresh()->ai_instructions);
        $this->assertStringContainsString('retur', $tenant->fresh()->ai_handoff_keywords);

        $page = Livewire::test(CreateLead::class)->assertSee('Detail pesanan')->assertSee('Produk diminati')->assertSee('Alamat kirim');
        foreach (self::SHOP_FIELDS as $field) {
            $page->assertFormFieldVisible($field);
        }
        foreach (['need', 'property_type', 'location', 'payment_method', 'budget_min', 'budget_max'] as $field) {
            $page->assertFormFieldHidden($field);
        }

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Sari', 'phone' => '081311112222', 'variant' => 'L', 'quantity' => 2, 'courier' => 'JNE', 'shipping_address' => 'Jl. Melati 3, Bandung'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(2, Lead::query()->where('name', 'Sari')->value('quantity'));

        $this->assertSame('Produk', PropertyResource::getNavigationLabel());
        $this->assertSame('Pesanan', Industry::t('visit'));
        $this->get(PropertyResource::getUrl())->assertOk()->assertSee('Produk');
        Livewire::test(ManageProperties::class)
            ->callAction('create', ['name' => 'Kaos Polos', 'property_type' => 'Pakaian', 'price_from' => 89000, 'status' => 'available'])
            ->assertHasNoActionErrors();
    }

    public function test_follow_ups_name_the_product_in_shop_words(): void
    {
        $tenant = $this->register('Toko Kaos', 'toko-kaos', 'retail');
        $lead = Lead::create(['name' => 'Sari Wulan', 'phone' => '081311113333', 'stage_id' => $this->stage($tenant, 'Tanya produk')->getKey()]);

        $text = app(TemplateRenderer::class)->render('Halo {nama}, soal {produk}', $lead, auth()->user(), $tenant->name)['text'];

        $this->assertSame('Halo Sari, soal produk yang Anda tanyakan', $text);
    }

    public function test_without_a_choice_an_agency_is_a_property_agency(): void
    {
        $tenant = $this->register('Griya Lama', 'griya-lama', null);

        $this->assertSame('properti', $tenant->industry);
        $this->assertSame('properti', $this->makeTenant('agensi-lain')->industry);
    }

    public function test_switching_an_agency_without_leads_applies_the_whole_template(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        Livewire::test(ListLeads::class)->callAction('agencySettings', data: ['industry' => 'retail', 'show_property_fields' => true])->assertHasNoActionErrors();

        $this->assertSame('retail', $tenant->fresh()->industry);
        $this->assertSame(['Tanya produk', 'Order masuk', 'Menunggu pembayaran', 'Dikemas & dikirim', 'Selesai', 'Batal'], $this->stages());
        $this->assertFalse(LostReason::query()->where('label', 'KPR ditolak')->exists());
        $this->assertFalse(LeadSource::query()->where('name', 'Portal properti')->exists());
        $this->assertStringContainsString('kurir', $tenant->fresh()->ai_instructions);
    }

    public function test_switching_an_agency_with_leads_keeps_every_lead_and_only_adds(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $survey = Stage::query()->where('name', 'Jadwal survei')->firstOrFail();
        $reason = LostReason::query()->where('label', 'KPR ditolak')->firstOrFail();
        $source = LeadSource::query()->where('name', 'Portal properti')->firstOrFail();
        $lead = $this->makeLead($tenant, [
            'name' => 'Budi', 'stage_id' => $survey->getKey(), 'lost_reason_id' => $reason->getKey(), 'lead_source_id' => $source->getKey(),
            'location' => 'Bogor', 'budget_max' => 900_000_000,
        ]);
        $tenant->forceFill(['ai_instructions' => 'Gaya kami sendiri.'])->save();
        $before = Stage::query()->count();

        Livewire::test(ListLeads::class)->callAction('agencySettings', data: ['industry' => 'retail', 'show_property_fields' => true])->assertHasNoActionErrors();

        $lead = $lead->fresh();
        $this->assertSame($survey->getKey(), $lead->stage_id);
        $this->assertSame($reason->getKey(), $lead->lost_reason_id);
        $this->assertSame($source->getKey(), $lead->lead_source_id);
        $this->assertSame('Bogor', $lead->location);
        $this->assertSame(900_000_000, (int) $lead->budget_max);

        $this->assertSame($before + 6, Stage::query()->count(), 'Old stages stay, the shop stages are added');
        $this->assertTrue(LostReason::query()->where('label', 'KPR ditolak')->exists());
        $this->assertTrue(LostReason::query()->where('label', 'Ongkir mahal')->exists());
        $this->assertTrue(LeadSource::query()->where('name', 'Instagram')->exists());
        $this->assertSame(1, LeadSource::query()->where('name', 'Iklan Meta')->count(), 'Same names are not added twice');

        // Open stages still come before won and lost ones
        $types = Stage::query()->orderBy('position')->get()->map(fn (Stage $s) => $s->type->value)->all();
        $this->assertSame($types, collect($types)->sortBy(fn ($t) => ['open' => 0, 'won' => 1, 'lost' => 2][$t])->values()->all());
        $this->assertSame(range(1, count($types)), Stage::query()->orderBy('position')->pluck('position')->all());

        $this->assertSame('Gaya kami sendiri.', $tenant->fresh()->ai_instructions, 'Own AI texts are kept');
        $this->assertSame('Produk', Industry::t('catalog'));

        // And back: nothing is lost either way
        app(IndustryTemplate::class)->switch($tenant->fresh(), 'properti');
        $this->assertSame($survey->getKey(), $lead->fresh()->stage_id);
        $this->assertTrue(Stage::query()->where('name', 'Selesai')->exists());
    }

    public function test_the_platform_sales_agency_keeps_its_setup(): void
    {
        $tenant = $this->makeTenant();
        config(['jitu.platform_sales_tenant' => $tenant->slug]);
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $stages = $this->stages();

        Livewire::test(ListLeads::class)->assertActionVisible('agencySettings')
            ->callAction('agencySettings', data: ['industry' => 'retail'])->assertHasNoActionErrors();

        $this->assertSame('properti', $tenant->fresh()->industry);
        $this->assertSame($stages, $this->stages());
        $this->assertFalse($tenant->fresh()->showsIndustryFields());
    }

    public function test_trial_emails_use_the_shop_words(): void
    {
        $tenant = $this->register('Toko Kaos', 'toko-kaos', 'retail');
        $step = config('trial_emails.steps.ai_knowledge');

        $mail = app(TrialEmails::class)->mail('ai_knowledge', $step, $tenant->currentSubscription(), auth()->user());

        $this->assertSame('Ajari AI tentang produk Toko Kaos', $mail->subjectLine);
        $this->assertStringContainsString('ongkir dan kurir', $mail->body);
        $this->assertStringNotContainsString('tipe unit', $mail->body);
    }

    public function test_a_shop_gets_shop_sample_data(): void
    {
        $this->register('Toko Kaos', 'toko-kaos', 'retail');

        $this->assertSame(30, app(DemoData::class)->load());
        $this->assertTrue(Property::query()->where('name', 'Kaos Polos Premium (contoh)')->exists());
        $done = Lead::query()->whereRelation('stage', 'name', 'Selesai')->firstOrFail();
        $this->assertNotNull($done->courier);
        $this->assertSame($done->property->price_from * $done->quantity, (int) $done->deal_value);
    }
}
