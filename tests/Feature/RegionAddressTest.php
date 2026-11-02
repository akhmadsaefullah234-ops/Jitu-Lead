<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Ai\ShopTools;
use App\Automation\StageAutomation;
use App\LeadForms\FormFields;
use App\LeadForms\FormTemplates;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\User;
use App\Regions\RegionDirectory;
use App\Shipping\Shipping;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Indonesia's regions in the local database: the import, the dropdown endpoint, address forms, leads, shipping and the AI. */
class RegionAddressTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    private function import(): void
    {
        $this->assertSame(0, Artisan::call('regions:import', [
            '--wilayah' => base_path('tests/fixtures/regions/wilayah.sql'),
            '--kodepos' => base_path('tests/fixtures/regions/wilayah_kodepos.sql'),
        ]));
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function form(array $address = []): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Order', 'slug' => 'order', 'status' => 'active', 'design' => ['title' => 'Order'],
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                $address + ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat lengkap', 'required' => true, 'maps_to' => 'shipping_address', 'mode' => 'region',
                    'required_parts' => ['street', 'landmark', 'rt_rw', 'kelurahan', 'kecamatan', 'kota']],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));
    }

    private function send(array $address): TestResponse
    {
        return $this->post('/f/'.$this->tenant->slug.'/order', ['f' => ['nama' => 'Rina', 'wa' => '081234567890', 'alamat' => $address]], ['Accept' => 'application/json']);
    }

    private function bogor(array $override = []): array
    {
        return array_replace(['provinsi' => '32', 'kota' => '32.01', 'kecamatan' => '32.01.02', 'kelurahan' => '32.01.02.2001',
            'kode_pos' => '', 'street' => 'Jl. Raya Mercedes', 'landmark' => 'No. 12', 'rt_rw' => '003/004'], $override);
    }

    // ---- Import --------------------------------------------------------------------------------------------------

    public function test_the_import_fills_every_level_with_postal_codes_and_can_be_run_again(): void
    {
        $this->assertFalse(RegionDirectory::available());
        $this->import();

        $this->assertTrue(RegionDirectory::available());
        $this->assertSame(3, Region::query()->where('level', Region::PROVINCE)->count());
        $this->assertSame(['32', 1, 'Kabupaten Bogor'], [Region::find('32.01')->parent_code, Region::query()->where('level', 1)->where('code', '32')->count(), Region::find('32.01')->name]);
        $this->assertSame('16965', Region::find('32.01.02.2001')->postal_code);
        $this->assertSame('bogor', Region::find('32.01')->search, 'names are searched without "Kabupaten"/"Kota"');

        // A newer dump replaces the old rows and the cached answers.
        RegionDirectory::children('32');
        $newer = str_replace("'Kabupaten Bogor'", "'Kabupaten Bogor Raya'", (string) file_get_contents(base_path('tests/fixtures/regions/wilayah.sql')));
        $path = tempnam(sys_get_temp_dir(), 'wil');
        file_put_contents($path, $newer);
        $this->assertSame(0, Artisan::call('regions:import', ['--wilayah' => $path, '--no-kodepos' => true]));
        unlink($path);

        $this->assertSame('Kabupaten Bogor Raya', Region::find('32.01')->name);
        $this->assertNull(Region::find('32.01.02.2001')->postal_code);
        $this->assertContains(['32.01', 'Kabupaten Bogor Raya'], RegionDirectory::children('32'));
        $this->assertSame(1, Artisan::call('regions:import', ['--wilayah' => '/tidak/ada.sql']), 'a missing source fails without emptying the table');
        $this->assertSame(3, Region::query()->where('level', Region::PROVINCE)->count());
    }

    // ---- Dropdown endpoint ---------------------------------------------------------------------------------------

    public function test_the_endpoint_returns_one_level_at_a_time_with_cache_headers(): void
    {
        $this->import();

        $provinces = $this->getJson('/wilayah')->assertOk()->assertHeader('ETag')->json('items');
        $this->assertSame(['31', '32', '36'], collect($provinces)->pluck(0)->sort()->values()->all());
        $this->assertStringContainsString('dki', collect($provinces)->firstWhere(0, '31')[3], 'provinces are also found by their short names');
        $this->assertStringContainsString('max-age=86400', $this->get('/wilayah')->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $this->get('/wilayah')->headers->get('Cache-Control'));

        $cities = $this->getJson('/wilayah/32')->assertOk()->json('items');
        $this->assertSame(['Kabupaten Bandung', 'Kabupaten Bogor', 'Kota Bandung'], array_column($cities, 1), 'only the province\'s own, sorted');

        $villages = $this->getJson('/wilayah/32.01.02')->assertOk()->json('items');
        $this->assertContains(['32.01.02.2001', 'Wanaherang', '16965'], $villages);

        $etag = $this->get('/wilayah/32')->headers->get('ETag');
        $this->get('/wilayah/32', ['If-None-Match' => $etag])->assertStatus(304);
        $this->get('/wilayah/32.01.02.2001')->assertNotFound();
        $this->get('/wilayah/abc')->assertNotFound();
        $this->assertSame([], $this->getJson('/wilayah/99')->json('items'));
    }

    // ---- Forms ---------------------------------------------------------------------------------------------------

    public function test_a_region_address_renders_searchable_dropdowns_and_falls_back_to_typing_until_regions_are_imported(): void
    {
        $this->form();
        $before = $this->get('/f/'.$this->tenant->slug.'/order')->assertOk()->getContent();
        $this->assertStringNotContainsString('class="lf-g lf-addr lf-rg"', $before, 'nothing imported yet: typed address');
        $this->assertStringContainsString('name="f[alamat][kecamatan]"', $before);

        $this->import();
        $html = $this->get('/f/'.$this->tenant->slug.'/order')->assertOk()->getContent();
        $this->assertStringContainsString('class="lf-g lf-addr lf-rg" data-regions="'.url('/wilayah').'"', $html);
        $this->assertSame(4, substr_count($html, 'class="rg-q"'));
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('&quot;Daerah Khusus Ibukota Jakarta&quot;', $html, 'provinces come with the page; the rest loads per level');
        $this->assertStringNotContainsString('Kabupaten Bogor', $html);
        foreach (['provinsi', 'kota', 'kecamatan', 'kelurahan', 'kode_pos', 'street', 'landmark', 'rt_rw'] as $part) {
            $this->assertStringContainsString('name="f[alamat]['.$part.']"', $html);
        }
        $this->assertMatchesRegularExpression('/<span>Provinsi \*<\/span>/', $html, 'a required level makes the ones above it required');
        $this->assertStringContainsString('data-postal', $html);
    }

    public function test_the_order_template_uses_dropdowns_and_other_forms_keep_typing(): void
    {
        $order = collect(FormTemplates::attributes(FormTemplates::ORDER)['fields'])->firstWhere('key', 'alamat');
        $this->assertSame('region', FormFields::normalize([$order])[0]['mode']);

        $pesanan = collect(FormTemplates::attributes('pesanan')['fields'])->firstWhere('key', 'alamat');
        $this->assertArrayNotHasKey('mode', FormFields::normalize([$pesanan])[0]);
        $this->assertArrayNotHasKey('mode', FormFields::normalize([['key' => 'a', 'type' => 'address', 'label' => 'A', 'mode' => 'aneh']])[0]);
    }

    public function test_picked_regions_must_exist_and_belong_together(): void
    {
        $this->import();
        $this->form();

        $this->send($this->bogor(['kota' => '32.73']))->assertStatus(422)
            ->assertJsonFragment(['Kecamatan tidak berada di kota/kabupaten yang dipilih.']);
        $this->send($this->bogor(['provinsi' => '31']))->assertStatus(422)
            ->assertJsonFragment(['Kota/kabupaten tidak berada di provinsi yang dipilih.']);
        $this->send($this->bogor(['kelurahan' => '32.01.01.1001']))->assertStatus(422)
            ->assertJsonFragment(['Kelurahan/desa tidak berada di kecamatan yang dipilih.']);
        $this->send($this->bogor(['kecamatan' => '32.01.99']))->assertStatus(422)
            ->assertJsonFragment(['Pilihan kecamatan tidak dikenal. Pilih dari daftar.']);
        $this->send($this->bogor(['kota' => 'Bogor']))->assertStatus(422)->assertJsonValidationErrors(['f.alamat.kota']);
        $this->send($this->bogor(['provinsi' => '']))->assertStatus(422)->assertJsonValidationErrors(['f.alamat.provinsi']);
        $this->send($this->bogor(['rt_rw' => '']))->assertStatus(422)->assertJsonValidationErrors(['f.alamat.rt_rw']);
        $this->send($this->bogor(['landmark' => '']))->assertStatus(422)->assertJsonValidationErrors(['f.alamat.landmark']);
        $this->send($this->bogor(['kode_pos' => '123']))->assertStatus(422)->assertJsonValidationErrors(['f.alamat.kode_pos']);

        $this->assertSame(0, $this->in(fn () => Lead::query()->count()));
    }

    public function test_the_address_is_saved_on_the_lead_in_parts_with_official_names_and_the_postal_code(): void
    {
        $this->import();
        $this->form();

        $this->send($this->bogor())->assertOk();

        $lead = $this->in(fn () => Lead::query()->sole());
        $this->assertSame('Jl. Raya Mercedes, RT/RW 003/004, (No. 12), Desa Wanaherang, Kec. Gunungputri, Kabupaten Bogor, Jawa Barat 16965', $lead->shipping_address);
        $this->assertSame('Jawa Barat', $lead->shipping_province);
        $this->assertSame([
            'street' => 'Jl. Raya Mercedes', 'rt_rw' => '003/004', 'landmark' => 'No. 12', 'kelurahan' => 'Wanaherang', 'kecamatan' => 'Gunungputri',
            'kota' => 'Kabupaten Bogor', 'provinsi' => 'Jawa Barat', 'kode_pos' => '16965',
            'codes' => ['provinsi' => '32', 'kota' => '32.01', 'kecamatan' => '32.01.02', 'kelurahan' => '32.01.02.2001'],
        ], $lead->shipping_address_parts);

        // A postal code the buyer typed wins; a kelurahan (1xxx) is written as "Kel.", and Jakarta's province maps to its shipping name.
        $this->in(fn () => Lead::query()->delete());
        $this->post('/f/'.$this->tenant->slug.'/order', ['f' => ['nama' => 'Budi', 'wa' => '081298765432', 'alamat' => [
            'provinsi' => '31', 'kota' => '31.74', 'kecamatan' => '31.74.01', 'kelurahan' => '31.74.01.1001', 'kode_pos' => '12999',
            'street' => 'Jl. Tebet Raya', 'landmark' => 'depan apotek', 'rt_rw' => '01/02',
        ]]], ['Accept' => 'application/json'])->assertOk();
        $lead = $this->in(fn () => Lead::query()->sole());
        $this->assertStringContainsString('Kel. Tebet Timur, Kec. Tebet, Kota Administrasi Jakarta Selatan, DKI Jakarta 12999', $lead->shipping_address);
        $this->assertSame('DKI Jakarta', $lead->shipping_province);
    }

    public function test_typed_addresses_keep_working_with_regions_imported(): void
    {
        $this->import();
        $this->form(['mode' => 'free']);

        $this->send(['street' => 'Jl. Mawar 5', 'rt_rw' => '001/002', 'landmark' => 'dekat masjid', 'kelurahan' => 'Sukamaju', 'kecamatan' => 'Tambun Selatan',
            'kota' => 'Kab. Bekasi', 'provinsi' => 'Jawa Barat', 'kode_pos' => '17510'])->assertOk();

        $lead = $this->in(fn () => Lead::query()->sole());
        $this->assertSame('Jl. Mawar 5, RT/RW 001/002, (dekat masjid), Kel. Sukamaju, Kec. Tambun Selatan, Kab. Bekasi, Jawa Barat 17510', $lead->shipping_address);
        $this->assertSame('Jawa Barat', $lead->shipping_province);
        $this->assertSame('Tambun Selatan', $lead->shipping_address_parts['kecamatan']);
        $this->assertArrayNotHasKey('codes', $lead->shipping_address_parts);
    }

    // ---- Shipping and the AI -------------------------------------------------------------------------------------

    public function test_shipping_and_automation_recognise_official_names_short_names_and_districts(): void
    {
        $this->import();

        $this->assertSame(['name' => 'Kota Administrasi Jakarta Selatan', 'province' => 'DKI Jakarta'], RegionDirectory::matchCity('Jaksel'));
        $this->assertSame(['name' => 'Kota Tangerang Selatan', 'province' => 'Banten'], RegionDirectory::matchCity('tangsel'));
        $this->assertSame(['name' => 'Kabupaten Bandung', 'province' => 'Jawa Barat'], RegionDirectory::matchCity('Kab Bandung'));
        $this->assertSame(['name' => null, 'province' => 'Jawa Barat'], RegionDirectory::matchCity('Bandung'), 'city and regency: only the province is sure');
        $this->assertSame(['name' => 'Kabupaten Bogor', 'province' => 'Jawa Barat'], RegionDirectory::matchCity('Cibinong'), 'a district finds its regency');
        $this->assertNull(RegionDirectory::matchCity('Atlantis'));
        $this->assertSame('Jawa Barat', StageAutomation::provinceIn('Jl. Raya 1, RT 1/2, Gunungputri'));
        $this->assertArrayHasKey('Kota Bandung', RegionDirectory::searchCities('band'));
        $this->assertContains('Kota Tangerang Selatan', RegionDirectory::cityNames());

        $shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $quote = app(CurrentTenant::class)->run($shop, fn () => Shipping::quote($shop->fresh(), 'Jaksel', null, [['produk' => 'Kaos', 'jumlah' => 1]]));
        $this->assertSame('Kota Administrasi Jakarta Selatan', $quote->city, 'the quote, and so the AI, names the official destination');
        $this->assertSame('DKI Jakarta', $quote->province);
        $this->assertStringContainsString('Kota Administrasi Jakarta Selatan', $quote->toArray()['tujuan']);

        $instructions = app(CurrentTenant::class)->run($shop, fn () => ShopTools::for($shop->fresh())?->instructions());
        $this->assertStringContainsString('nama resmi', (string) $instructions);
    }
}
