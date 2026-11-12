<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Filament\Resources\LeadForms\Pages\EditLeadForm;
use App\LeadForms\FormFields;
use App\LeadForms\FormQuote;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\User;
use App\Regions\RegionDirectory;
use App\Shipping\ShippingProvider;
use App\Shipping\ShippingQuote;
use App\Shipping\ShippingRequest;
use App\Shipping\ZoneShippingRates;
use App\Support\CurrentTenant;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Tampilkan rincian harga dan ongkir" (design.show_summary, read only by LeadForm::showsSummary): on unless turned
 * off, so forms saved before it keep their summary. Off, the box is not drawn, the page asks nothing of /ringkasan
 * and /ringkasan prices nothing; the lead, its order and the AI's summary stay exactly the same.
 */
class FormSummarySwitchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $bag;

    /** How often the shipping provider was asked for a price. */
    private int $priced = 0;

    private const MAKASSAR = ['kota' => '73.71', 'kecamatan' => '73.71.01', 'kelurahan' => '73.71.01.1001'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();

        // As in production: a store that serializes, and no classes read back (see FormQuoteCacheTest).
        config(['cache.serializable_classes' => false, 'cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/testing/form-summary-'.getmypid())]);
        Cache::purge('file');
        Cache::setDefaultDriver('file');
        Cache::flush();

        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->bag = $this->in(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 199000, 'weight_grams' => 300]));

        foreach ([
            ['73', 'Sulawesi Selatan', 1, null, null], ['73.71', 'Kota Makassar', 2, '73', null], ['73.71.01', 'Mariso', 3, '73.71', null],
            ['73.71.01.1001', 'Bontorannu', 4, '73.71.01', '90121'],
        ] as [$code, $name, $level, $parent, $postal]) {
            Region::query()->insert(['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent, 'postal_code' => $postal, 'search' => RegionDirectory::searchKey($name)]);
        }
        RegionDirectory::flush();

        // Counts every price asked of the provider (Shipping::quote), still priced by the shop's zone table.
        $this->app->bind(ZoneShippingRates::class, function ($app, array $params) {
            $zone = new ZoneShippingRates($params['settings']);

            return new class($zone, fn () => $this->priced++) implements ShippingProvider
            {
                public function __construct(private ShippingProvider $inner, private \Closure $count) {}

                public function quote(ShippingRequest $request): ShippingQuote
                {
                    ($this->count)();

                    return $this->inner->quote($request);
                }
            };
        });
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ---- Default ---------------------------------------------------------------------------------------------------

    public function test_a_form_without_the_setting_still_shows_the_summary(): void
    {
        $form = $this->form();
        $this->assertArrayNotHasKey('show_summary', $form->design);
        $this->assertTrue($form->showsSummary());
        $this->assertTrue($this->in(fn () => FormQuote::available($this->shop, $form)));

        $html = $this->get('/f/quicker-sport/pendek')->assertOk()->getContent();
        $this->assertStringContainsString('class="lf-sum"', $html);
        $this->assertStringContainsString('data-quote="'.url('/f/quicker-sport/pendek/ringkasan').'"', $html);

        $this->assertTrue($this->summary()['ok']);
        $this->assertSame(1, $this->priced);
    }

    public function test_only_false_turns_it_off(): void
    {
        foreach ([[true, true], [null, true], [false, false], ['0', false], [0, false], ['1', true]] as [$value, $shows]) {
            $this->assertSame($shows, (new LeadForm(['design' => ['show_summary' => $value]]))->showsSummary(), json_encode($value));
        }
        $this->assertTrue((new LeadForm)->showsSummary(), 'no design at all');
    }

    // ---- Off -------------------------------------------------------------------------------------------------------

    public function test_off_draws_no_box_asks_no_quote_and_the_endpoint_prices_nothing(): void
    {
        $form = $this->form(['show_summary' => false]);
        $this->assertFalse($this->in(fn () => FormQuote::available($this->shop, $form)));
        $this->assertTrue($this->in(fn () => FormQuote::eligible($this->shop, $form)), 'it could show: only the switch is off');

        $html = $this->get('/f/quicker-sport/pendek')->assertOk()->getContent();
        $this->assertStringNotContainsString('class="lf-sum"', $html);
        $this->assertStringNotContainsString('data-quote="', $html);
        $this->assertStringNotContainsString('/ringkasan', $html);
        $this->assertStringContainsString('name="f[alamat][kota]"', $html, 'the address is still asked');

        $this->assertSame(['ok' => false], $this->summary());
        $this->assertSame(0, $this->priced, 'Shipping::quote is never called');
    }

    public function test_off_also_holds_inside_a_landing_page_and_its_iframe(): void
    {
        $form = $this->form(['show_summary' => false], withProduct: false);
        $page = $this->in(fn () => LandingPage::create(['title' => 'Tas', 'slug' => 'tas', 'status' => 'published', 'property_id' => $this->bag->id,
            'blocks' => [['type' => 'form', 'data' => ['form_id' => $form->id, 'heading' => 'Pesan']]]]));

        $html = $this->get('/p/quicker-sport/tas')->assertOk()->getContent();
        $this->assertStringContainsString('Pesan', $html);
        $this->assertStringNotContainsString('class="lf-sum"', $html);
        $this->assertStringNotContainsString('data-quote="', $html);
        $this->assertSame(['ok' => false], $this->summary(['page' => $page->id]), 'the iframe asks with ?page=');
        $this->assertSame(0, $this->priced);

        // The same page with the switch on: the product comes from the page.
        $this->in(fn () => $form->update(['design' => ['show_summary' => true] + $form->design]));
        $this->assertStringContainsString('class="lf-sum"', $this->get('/p/quicker-sport/tas')->getContent());
        $this->assertTrue($this->summary(['page' => $page->id])['ok']);
        $this->assertSame(1, $this->priced);
    }

    public function test_off_the_lead_its_address_product_and_order_value_are_the_same_as_on(): void
    {
        $this->form([], 'nyala');
        $this->form(['show_summary' => false]);

        $columns = ['property_id', 'quantity', 'variant', 'shipping_address', 'shipping_province', 'shipping_address_parts', 'deal_value', 'stage_id'];
        $leads = [];
        foreach (['nyala' => '081234567890', 'pendek' => '081234567891'] as $slug => $wa) {
            $this->post('/f/quicker-sport/'.$slug, ['f' => ['nama' => 'Rina', 'wa' => $wa, 'jumlah' => '2', 'alamat' => self::MAKASSAR + ['street' => 'Jl. Nusantara No. 5 RT 2/RW 1']]],
                ['Accept' => 'application/json'])->assertOk()->assertJson(['ok' => true]);
            $leads[$slug] = $this->in(fn () => Lead::query()->latest('id')->firstOrFail()->only($columns));
        }

        $this->assertSame($this->bag->id, $leads['pendek']['property_id']);
        $this->assertSame(2, (int) $leads['pendek']['quantity']);
        $this->assertStringContainsString('Kota Makassar', $leads['pendek']['shipping_address']);
        $this->assertSame('Sulawesi Selatan', $leads['pendek']['shipping_province']);
        $this->assertSame($leads['nyala'], $leads['pendek'], 'the switch changes nothing on the lead');
        $this->assertSame(0, $this->priced, 'sending a form prices nothing either way');
    }

    // ---- The editor ------------------------------------------------------------------------------------------------

    public function test_on_off_on_from_the_editor_moves_updated_at_and_leaves_no_old_price(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $form = $this->form();
        $keys = $this->writtenKeys();

        $page = Livewire::test(EditLeadForm::class, ['record' => $form->getKey()])
            ->assertFormFieldVisible('design.show_summary')->assertFormSet(['design.show_summary' => true])
            ->assertDontSee('Rincian tampil otomatis setelah');
        $first = $this->summary();
        $this->assertTrue($first['ok']);

        $this->travel(5)->seconds();
        $page->fillForm(['design.show_summary' => false])->call('save')->assertHasNoFormErrors();
        $off = $form->fresh();
        $this->assertFalse($off->design['show_summary']);
        $this->assertFalse($off->showsSummary());
        $this->assertTrue($off->updated_at->gt($form->updated_at), 'saving the switch moves updated_at, which is in the quote key');
        $this->assertSame('Order', $off->design['title'], 'the rest of the design is kept');
        $this->assertSame(['ok' => false], $this->summary());
        $this->assertStringNotContainsString('class="lf-sum"', $this->get('/f/quicker-sport/pendek')->getContent());
        $this->assertSame(1, $this->priced);

        $this->travel(5)->seconds();
        $page->fillForm(['design.show_summary' => true])->call('save')->assertHasNoFormErrors();
        $on = $form->fresh();
        $this->assertTrue($on->showsSummary());
        $this->assertTrue($on->updated_at->gt($off->updated_at));
        $again = $this->summary();
        $this->assertSame(2, $this->priced, 'priced again under the new key, not read from the old entry');
        $this->assertSame($first, $again);
        $this->assertCount(2, array_unique($keys->all));
        $this->assertStringContainsString('.'.$on->updated_at->timestamp.'.', $keys->all[1]);
    }

    public function test_the_switch_is_not_shown_on_a_form_that_cannot_show_the_summary_but_says_why(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        // No product: neither its own nor a landing page's.
        $noProduct = $this->form([], 'tanpa-produk', withProduct: false);
        Livewire::test(EditLeadForm::class, ['record' => $noProduct->getKey()])
            ->assertFormFieldHidden('design.show_summary')
            ->assertSee('Rincian tampil otomatis setelah formulir punya produk berharga dan kolom Alamat pengiriman.');

        // Picking a product in the editor (not yet saved) brings the switch.
        Livewire::test(EditLeadForm::class, ['record' => $noProduct->getKey()])
            ->fillForm(['property_id' => $this->bag->id])
            ->assertFormFieldVisible('design.show_summary')->assertDontSee('Rincian tampil otomatis setelah');

        // A product, but no address for shipping.
        $noAddress = $this->in(fn () => LeadForm::create([
            'name' => 'Tanya', 'slug' => 'tanya', 'status' => 'active', 'design' => ['title' => 'Tanya', 'color' => '#dc2626', 'show_summary' => false], 'property_id' => $this->bag->id,
            'submit' => ['label' => 'Kirim'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
            ]),
        ]));
        Livewire::test(EditLeadForm::class, ['record' => $noAddress->getKey()])
            ->assertFormFieldHidden('design.show_summary')->assertSee('Rincian tampil otomatis setelah')
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse($noAddress->fresh()->design['show_summary'], 'a hidden switch keeps what was chosen');

        // The product comes from a landing page that shows the form.
        $this->in(fn () => LandingPage::create(['title' => 'Tas', 'slug' => 'tas', 'status' => 'published', 'property_id' => $this->bag->id,
            'blocks' => [['type' => 'form', 'data' => ['form_id' => $noProduct->id]]]]));
        Livewire::test(EditLeadForm::class, ['record' => $noProduct->getKey()])->assertFormFieldVisible('design.show_summary');
    }

    public function test_the_editor_preview_follows_the_switch(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $form = $this->form();
        $page = Livewire::test(EditLeadForm::class, ['record' => $form->getKey()]);

        $on = $page->instance()->previewHtml();
        $this->assertMatchesRegularExpression('/class="lf-sum" data-sum\s+aria-live/', $on, 'drawn open: the preview runs no script');
        $this->assertStringContainsString('Harga 1 x Rp 199.000', $on);

        $page->fillForm(['design.show_summary' => false]);
        $off = $page->instance()->previewHtml();
        $this->assertStringNotContainsString('class="lf-sum"', $off, 'unsaved, already in the preview');
        $this->assertStringNotContainsString('data-quote="', $off);
        $this->assertTrue($form->fresh()->showsSummary(), 'nothing saved');
    }

    // ---- Tenants ---------------------------------------------------------------------------------------------------

    public function test_another_shop_can_neither_read_nor_change_the_setting(): void
    {
        $form = $this->form(['show_summary' => false]);
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        app(CurrentTenant::class)->run($other, fn () => Property::create(['name' => 'Tas Lain', 'price_from' => 99000, 'weight_grams' => 300]));

        $this->actingInTenant($this->adminOf($other), $other);
        $this->assertNull(app(CurrentTenant::class)->run($other, fn () => LeadForm::query()->find($form->id)));
        Livewire::test(EditLeadForm::class, ['record' => $form->getKey()])->assertNotFound();
        $this->get('/app/toko-lain/lead-forms/'.$form->id.'/edit')->assertNotFound();

        // The form's slug under the other shop's address is not this form.
        $this->getJson('/f/toko-lain/pendek/ringkasan?'.http_build_query(['alamat' => self::MAKASSAR, 'jumlah' => 1]))->assertNotFound();
        $this->get('/f/toko-lain/pendek')->assertNotFound();

        $this->assertFalse($form->fresh()->showsSummary(), 'unchanged');
        $this->assertSame(0, $this->priced);
    }

    // ---- Helpers ---------------------------------------------------------------------------------------------------

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function form(array $design = [], string $slug = 'pendek', bool $withProduct = true): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Form '.$slug, 'slug' => $slug, 'status' => 'active', 'design' => ['title' => 'Order', 'color' => '#dc2626'] + $design,
            'property_id' => $withProduct ? $this->bag->id : null,
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'jumlah', 'type' => 'number', 'label' => 'Jumlah', 'required' => true, 'maps_to' => 'quantity'],
                ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address', 'mode' => 'region',
                    'parts' => ['street', 'kota', 'kecamatan', 'kelurahan', 'kode_pos'], 'required_parts' => ['street', 'kota', 'kecamatan', 'kelurahan'],
                    'address_options' => ['hide_postal', 'city_first']],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));
    }

    private function summary(array $query = []): array
    {
        return $this->getJson('/f/quicker-sport/pendek/ringkasan?'.http_build_query($query + ['alamat' => self::MAKASSAR, 'jumlah' => 2]))->assertOk()->json();
    }

    private function writtenKeys(): object
    {
        $keys = new class
        {
            public array $all = [];
        };
        Event::listen(KeyWritten::class, function (KeyWritten $e) use ($keys) {
            if (str_starts_with($e->key, FormQuote::KEY_PREFIX)) {
                $keys->all[] = $e->key;
            }
        });

        return $keys;
    }
}
