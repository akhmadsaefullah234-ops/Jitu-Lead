<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Filament\Resources\LandingPages\LandingPageResource;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\Filament\Resources\LeadForms\Pages\CreateLeadForm;
use App\LandingPages\Navigation;
use App\LandingPages\Sections;
use App\LandingPages\Templates;
use App\LeadForms\FormFields;
use App\LeadForms\FormTemplates;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\User;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** The header button, bottom bar and floating WhatsApp button as page settings; the shop's landing and order form templates. */
class LandingNavigationRetailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->tenant->regenerateCaptureToken();
        $this->shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
        $this->shop->regenerateCaptureToken();

        foreach ([$this->tenant, $this->shop] as $t) {
            Artisan::call('plan:set', ['tenant' => $t->slug, 'plan' => 'agensi']);
            $t->unsetRelation('subscription');
        }
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $fn);
    }

    private function page(Tenant $tenant, array $attributes = []): LandingPage
    {
        return $this->in($tenant, fn () => LandingPage::create($attributes + [
            'title' => 'Promo', 'slug' => 'promo', 'status' => 'published', 'color' => '#dc2626',
            'blocks' => [['type' => 'hero', 'version' => 1, 'props' => ['headline' => 'Judul promo']]],
        ]));
    }

    private function html(Tenant $tenant, string $slug = 'promo'): string
    {
        return $this->get("/p/{$tenant->slug}/{$slug}")->assertOk()->getContent();
    }

    private function assertNoNavigation(string $html): void
    {
        $this->assertStringNotContainsString('class="dock"', $html);
        $this->assertStringNotContainsString('class="has-dock"', $html);
        $this->assertStringNotContainsString('class="waf"', $html);
        $this->assertStringNotContainsString('class="jb ', explode('</header>', explode('<header class="top">', $html)[1])[0], 'no header button');
        $this->assertStringNotContainsString('>Daftar</a>', $html);
    }

    // ---- A: header, bottom bar and floating WhatsApp ------------------------------------------------------------

    public function test_pages_start_without_header_button_bottom_bar_or_floating_whatsapp_for_every_business(): void
    {
        foreach ([[$this->tenant, 'kpr'], [$this->tenant, 'kosong'], [$this->shop, 'produk_promo'], [$this->shop, 'kosong']] as [$tenant, $template]) {
            $this->actingInTenant($this->adminOf($tenant), $tenant);
            Livewire::test(CreateLandingPage::class)->fillForm(['template' => $template, 'title' => "Halaman $template", 'slug' => 'halaman-'.str_replace('_', '-', $template)])
                ->call('create')->assertHasNoFormErrors();

            $page = $this->in($tenant, fn () => LandingPage::where('slug', 'halaman-'.str_replace('_', '-', $template))->firstOrFail());
            $this->assertEquals(Navigation::defaults(), $page->navigation, "{$tenant->slug} $template");
            $this->in($tenant, fn () => WaChannel::firstOrCreate(['name' => 'Utama'], ['type' => 'official', 'phone' => '0811 222 333', 'status' => 'connected', 'position' => 1]));

            $html = $this->get(LandingPageResource::previewUrl($page))->assertOk()->getContent();
            $this->assertNoNavigation($html);
            $this->assertStringNotContainsString('padding-bottom: 4.5rem; }', explode('body.has-dock', $html)[0], 'no blank space at the bottom');
        }
    }

    public function test_existing_pages_are_switched_off_by_the_migration(): void
    {
        $migration = require database_path('migrations/2026_11_09_000100_add_landing_navigation.php');
        $migration->down();
        DB::table('landing_pages')->insert(['tenant_id' => $this->tenant->getKey(), 'title' => 'Lama', 'slug' => 'lama', 'status' => 'published', 'color' => '#dc2626',
            'blocks' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
        $migration->up();

        $page = $this->in($this->tenant, fn () => LandingPage::where('slug', 'lama')->firstOrFail());
        $this->assertEquals(Navigation::defaults(), $page->navigation);
        $this->assertFalse($page->navigation['header']['show']);
        $this->assertSame('off', $page->navigation['dock']['mode']);
        $this->assertFalse($page->navigation['wa_float']['show']);
        $this->assertNoNavigation($this->html($this->tenant, 'lama'));
    }

    public function test_header_bottom_bar_and_floating_whatsapp_follow_the_settings(): void
    {
        $this->in($this->tenant, fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890']));
        $this->page($this->tenant, ['navigation' => [
            'header' => ['show' => true, 'button' => ['label' => 'Telepon kami', 'action' => 'tel', 'tel' => '0812 1111 2222', 'color' => 'dark', 'pixel' => ['meta' => 'Contact']]],
            'dock' => ['mode' => '2',
                'first' => ['label' => 'Chat sales', 'action' => 'whatsapp', 'wa_target' => 'manual', 'wa_number' => '0813 3333 4444', 'wa_message' => 'Halo {judul}', 'color' => 'wa'],
                'second' => ['label' => 'Isi formulir', 'action' => 'scroll', 'color' => 'brand']],
            'wa_float' => ['show' => true, 'target' => 'rotate', 'message' => 'Tanya {judul}'],
        ]]);

        $html = $this->html($this->tenant);
        $header = explode('</header>', explode('<header class="top">', $html)[1])[0];
        $this->assertStringContainsString('href="tel:+6281211112222"', $header);
        $this->assertStringContainsString('jb-dark jb-sm', $header);
        $this->assertStringContainsString('Telepon kami', $header);
        $this->assertStringContainsString('&quot;Contact&quot;', $header, 'the header button has its own pixel');

        $this->assertStringContainsString('<body  class="has-dock" >', $html);
        $dock = explode('</nav>', explode('<nav class="dock"', $html)[1])[0];
        $this->assertSame(2, substr_count($dock, 'class="jb '));
        $this->assertStringContainsString('https://wa.me/6281333334444?text=Halo%20Promo', $dock);
        $this->assertStringContainsString('href="#daftar" data-action="scroll"', $dock);
        $this->assertStringContainsString('<a class="waf" href="'.route('go.wa', 'griya-prima').'?text=Tanya%20Promo"', $html);

        // One button only; a WhatsApp button with nowhere to go is left out, and with it the bar and its space.
        $this->in($this->tenant, fn () => LandingPage::first()->update(['navigation' => ['dock' => ['mode' => '1', 'first' => ['label' => 'Isi formulir', 'action' => 'scroll']]]]));
        $this->assertSame(1, substr_count(explode('</nav>', explode('<nav class="dock"', $this->html($this->tenant))[1])[0], 'class="jb '));

        $this->in($this->tenant, fn () => LandingPage::first()->update(['navigation' => ['dock' => ['mode' => '1', 'first' => ['label' => 'WA', 'action' => 'whatsapp', 'wa_target' => 'agency']]]]));
        $this->assertNoNavigation($this->html($this->tenant));
    }

    public function test_the_editor_saves_the_navigation_tab(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $page = $this->page($this->tenant);

        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
            ->assertFormSet(['navigation.header.show' => false, 'navigation.dock.mode' => 'off', 'navigation.wa_float.show' => false])
            ->fillForm([
                'navigation.header.show' => true,
                'navigation.header.button.label' => 'Hubungi',
                'navigation.header.button.action' => 'scroll',
                'navigation.dock.mode' => '1',
                'navigation.wa_float.show' => true,
                'navigation.wa_float.target' => 'manual',
                'navigation.wa_float.number' => '0812 9999 8888',
            ])->call('save')->assertHasNoFormErrors();

        $nav = $page->fresh()->navigation;
        $this->assertTrue($nav['header']['show']);
        $this->assertSame('Hubungi', $nav['header']['button']['label']);
        $this->assertSame('1', $nav['dock']['mode']);
        $this->assertSame('0812 9999 8888', $nav['wa_float']['number']);

        $html = $this->html($this->tenant);
        $this->assertStringContainsString('class="waf" href="https://wa.me/6281299998888', $html);
        $this->assertStringContainsString('>Hubungi</span>', $html);

        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
            ->fillForm(['navigation.wa_float.show' => true, 'navigation.wa_float.target' => 'manual', 'navigation.wa_float.number' => ''])
            ->call('save')->assertHasFormErrors(['navigation.wa_float.number']);
    }

    // ---- B: shop sections and templates ------------------------------------------------------------------------

    public function test_shops_get_shop_sections_and_templates_and_property_agencies_keep_theirs(): void
    {
        $retail = Sections::types('retail');
        $this->assertArrayHasKey('product', $retail);
        $this->assertArrayHasKey('reviews', $retail);
        $this->assertArrayHasKey('faq', $retail);
        $this->assertArrayNotHasKey('price', $retail);
        $this->assertArrayNotHasKey('kpr', $retail);
        $this->assertArrayHasKey('kpr', Sections::types('properti'));
        $this->assertArrayNotHasKey('product', Sections::types('properti'));
        $this->assertArrayHasKey('price', Sections::types('retail', ['price']), 'a section already on the page stays editable');

        $this->assertEqualsCanonicalizing(['kosong', 'produk_promo', 'produk_varian', 'terima_kasih', 'konversi_produk', 'konversi_produk_wa', 'konversi_layanan'], array_keys(Templates::options('retail')));
        $this->assertEqualsCanonicalizing(['kosong', 'kpr', 'cluster', 'villa', 'kavling', 'second', 'konversi_properti', 'konversi_properti_wa', 'konversi_layanan'], array_keys(Templates::options('properti')));

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(CreateLandingPage::class)->fillForm(['template' => 'produk_promo', 'title' => 'X', 'slug' => 'x'])->call('create')
            ->assertHasFormErrors(['template']);
    }

    public function test_every_shop_template_is_created_complete_and_renders_without_navigation(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);

        foreach (array_keys(Templates::options('retail')) as $key) {
            Livewire::test(CreateLandingPage::class)->fillForm(['template' => $key, 'title' => "Toko $key", 'slug' => 'toko-'.str_replace('_', '-', $key)])
                ->call('create')->assertHasNoFormErrors();

            $page = $this->in($this->shop, fn () => LandingPage::where('slug', 'toko-'.str_replace('_', '-', $key))->firstOrFail());
            $html = $this->get(LandingPageResource::previewUrl($page))->assertOk()->getContent();
            $this->assertNoNavigation($html);

            Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->assertOk()->call('save')->assertHasNoFormErrors();
        }

        $promo = $this->in($this->shop, fn () => LandingPage::where('slug', 'toko-produk-promo')->firstOrFail());
        $this->assertSame(['product', 'shop_perks', 'specs', 'reviews', 'faq', 'form'], array_column($promo->blocks, 'type'));
        $html = $this->get(LandingPageResource::previewUrl($promo))->getContent();
        $this->assertStringContainsString('<s>Rp 199.000</s><strong>Rp 149.000</strong>', $html);
        $this->assertStringContainsString('Hemat 25%', $html);
        $this->assertStringContainsString('Pesan Sekarang', $html);
        $this->assertStringContainsString('href="#daftar" data-action="scroll"', $html);
        $this->assertStringContainsString('Retur dengan video unboxing', $html);
        $this->assertStringContainsString('name="f[pembayaran]"', $html, 'the order form is on the page');
        $this->assertStringContainsString('ulasan asli', mb_strtolower(json_encode(Templates::blocks('produk_promo'))));

        $variants = $this->in($this->shop, fn () => LandingPage::where('slug', 'toko-produk-varian')->firstOrFail());
        $html = $this->get(LandingPageResource::previewUrl($variants))->getContent();
        $this->assertStringContainsString('data-pick="Navy"', $html);
        $this->assertStringContainsString('style="background: #1e3a8a"', $html);
        $this->assertStringContainsString('data-pick="XL"', $html);

        $thanks = $this->in($this->shop, fn () => LandingPage::where('slug', 'toko-terima-kasih')->firstOrFail());
        $this->assertSame(['meta' => 'Lead', 'tiktok' => 'Lead', 'google' => 'generate_lead'], array_filter($thanks->page_events));
        $html = $this->get(LandingPageResource::previewUrl($thanks))->getContent();
        $this->assertStringNotContainsString('name="f[whatsapp]"', $html, 'no form under the thank-you page');
        $this->assertSame(1, substr_count($html, 'data-action="whatsapp"'), 'one WhatsApp button');
    }

    public function test_a_product_page_takes_the_product_from_the_catalog_and_gets_its_own_order_form(): void
    {
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $product = $this->in($this->shop, fn () => Property::create(['name' => 'Tumbler Hijau', 'price_from' => 89000,
            'details' => ['Warna' => 'Hijau, Hitam', 'Ukuran' => '500 ml, 750 ml', 'Bahan' => 'Stainless steel']]));

        Livewire::test(CreateLandingPage::class)
            ->fillForm(['template' => 'produk_varian', 'title' => 'Tumbler', 'slug' => 'tumbler', 'product_id' => $product->getKey(), 'order_form' => 'new'])
            ->call('create')->assertHasNoFormErrors();

        $page = $this->in($this->shop, fn () => LandingPage::where('slug', 'tumbler')->firstOrFail());
        $productBlock = collect($page->blocks)->firstWhere('type', 'product')['props'];
        $this->assertSame('Tumbler Hijau', $productBlock['name']);
        $this->assertSame(89000, $productBlock['price']);
        $this->assertSame(['Hijau', 'Hitam'], array_column($productBlock['variants'], 'name'));
        $this->assertSame(['500 ml', '750 ml'], $productBlock['sizes']);
        $this->assertSame([['label' => 'Bahan', 'value' => 'Stainless steel']], collect($page->blocks)->firstWhere('type', 'specs')['props']['rows']);
        $this->assertSame($product->getKey(), $page->property_id);

        $form = $this->in($this->shop, fn () => LeadForm::where('name', 'Order Tumbler Hijau')->firstOrFail());
        $this->assertSame($form->getKey(), collect($page->blocks)->firstWhere('type', 'form')['props']['form_id']);
        $this->assertSame(['Hijau', 'Hitam'], collect($form->fieldList())->firstWhere('key', 'varian')['options']);
        $this->assertStringContainsString("mau order Tumbler Hijau:\nVarian: {varian}", $form->after_submit['wa_message']);

        // A second product page can reuse that form, or get its own.
        Livewire::test(CreateLandingPage::class)
            ->fillForm(['template' => 'produk_promo', 'title' => 'Tumbler 2', 'slug' => 'tumbler-2', 'order_form' => (string) $form->getKey()])
            ->call('create')->assertHasNoFormErrors();
        $second = $this->in($this->shop, fn () => LandingPage::where('slug', 'tumbler-2')->firstOrFail());
        $this->assertSame($form->getKey(), collect($second->blocks)->firstWhere('type', 'form')['props']['form_id']);
        $this->assertSame(1, $this->in($this->shop, fn () => LeadForm::where('name', 'like', 'Order%')->count()));
    }

    // ---- B: the "Order produk" form ----------------------------------------------------------------------------

    public function test_order_form_template_is_offered_to_shops_and_maps_every_answer_onto_the_lead(): void
    {
        $attrs = FormTemplates::attributes(FormTemplates::ORDER);
        $maps = array_column($attrs['fields'], 'maps_to', 'key');
        $this->assertSame(['nama' => 'name', 'whatsapp' => 'phone', 'alamat' => 'shipping_address', 'varian' => 'variant', 'jumlah' => 'quantity',
            'pembayaran' => 'payment_type', 'catatan' => 'note'], $maps);
        $address = collect($attrs['fields'])->firstWhere('key', 'alamat');
        $this->assertSame(array_keys(FormFields::ADDRESS_PARTS), $address['parts'], 'every sub-field is shown');
        $this->assertSame(['street', 'kecamatan', 'kota'], $address['required_parts']);
        $this->assertSame(['rt_or_dusun', 'landmark_if_no_rt'], $address['rules']);
        $this->assertSame(['Transfer' => 'transfer', 'COD (bayar di tempat)' => 'cod'], collect($attrs['fields'])->firstWhere('key', 'pembayaran')['payment_map']);
        $this->assertSame('region', $address['mode']);
        $this->assertSame('whatsapp', $attrs['after_submit']['type']);

        $this->assertArrayHasKey('order', FormTemplates::options('retail'));
        $this->assertArrayNotHasKey('order', FormTemplates::options('properti'));

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        Livewire::test(CreateLeadForm::class)->fillForm(['template' => 'order', 'name' => 'Order Kaos', 'slug' => 'order-kaos'])->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateLeadForm::class)->fillForm(['template' => 'order', 'name' => 'Order Topi', 'slug' => 'order-topi'])->call('create')->assertHasNoFormErrors();
        $form = $this->in($this->shop, fn () => LeadForm::where('slug', 'order-kaos')->firstOrFail());
        $form->update(['after_submit' => ['wa_target' => 'manual', 'wa_number' => '0812 5555 6666'] + $form->after_submit]);

        $answers = ['nama' => 'Sari', 'whatsapp' => '0812 3456 7890', 'varian' => 'Hitam', 'jumlah' => '2', 'pembayaran' => 'COD (bayar di tempat)',
            'alamat' => ['street' => 'Jl. Melati 3', 'landmark' => 'pagar hijau', 'rt_rw' => '002/005', 'kecamatan' => 'Coblong', 'kota' => 'Kota Bandung', 'provinsi' => 'Jawa Barat']];

        $this->postJson('/f/toko-uji/order-kaos', ['f' => ['alamat' => ['rt_rw' => '', 'landmark' => ''] + $answers['alamat']] + $answers])
            ->assertStatus(422)->assertJsonPath('errors', fn ($e) => $e['f.alamat'] === ['Isi RT/RW atau dusun/kampung.', 'Isi nomor rumah atau patokan karena alamat tanpa RT/RW.']);

        $response = $this->postJson('/f/toko-uji/order-kaos', ['f' => $answers])->assertOk()->assertJsonPath('action.type', 'whatsapp');
        $text = urldecode(explode('?text=', $response->json('action.url'))[1]);
        $this->assertStringStartsWith('https://wa.me/6281255556666', $response->json('action.url'));
        $this->assertStringContainsString('saya Sari mau order', $text);
        $this->assertStringContainsString("Varian: Hitam\nJumlah: 2\n", $text);
        $this->assertStringNotContainsString('Catatan', $text, 'a field left empty is not in the message');
        $this->assertStringContainsString('Pembayaran: COD (bayar di tempat)', $text);
        $this->assertStringContainsString('Alamat: Jl. Melati 3, RT/RW 002/005, (pagar hijau), Kec. Coblong, Kota Bandung, Jawa Barat', $text);

        $lead = $this->in($this->shop, fn () => Lead::firstOrFail());
        $this->assertSame('Hitam', $lead->variant);
        $this->assertSame(2, (int) $lead->quantity);
        $this->assertSame('cod', $lead->payment_type);
        $this->assertSame('Jawa Barat', $lead->shipping_province);
        $this->assertStringContainsString('RT/RW 002/005', $lead->shipping_address);

        $this->postJson('/f/toko-uji/order-kaos', ['f' => ['whatsapp' => '0812 7777 8888', 'pembayaran' => 'Transfer'] + $answers])->assertOk();
        $this->assertSame('transfer', $this->in($this->shop, fn () => Lead::where('phone', '+6281277778888')->value('payment_type')));
    }
}
