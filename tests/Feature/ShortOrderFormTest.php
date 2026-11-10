<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Ai\BuyerFacts;
use App\LandingPages\AbTest;
use App\LeadForms\FormFields;
use App\Leads\AddressCheck;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Region;
use App\Models\Tenant;
use App\Models\User;
use App\Regions\RegionDirectory;
use App\Shipping\Carriers\CarrierLocation;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\ProviderLocations;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The shortened Tas Badminton order form: postal code from the kelurahan, dropdowns from the city with the
 * province derived, quantity from 1, RT/RW | dusun, the soft RT/RW check, two steps, the order summary and the
 * A/B test of two landing pages.
 */
class ShortOrderFormTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Property $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->bag = $this->in(fn () => Property::create(['name' => 'Tas Badminton', 'price_from' => 199000, 'weight_grams' => 300, 'shipping_promos' => [
            ['type' => 'discount', 'amount' => 30000, 'area' => 'outside_java_bali', 'min_quantity' => 1],
        ]]));

        $this->assertSame(0, Artisan::call('regions:import', [
            '--wilayah' => base_path('tests/fixtures/regions/wilayah.sql'),
            '--kodepos' => base_path('tests/fixtures/regions/wilayah_kodepos.sql'),
        ]));

        // Twins (Kota/Kabupaten Bekasi), a village without a postal code, and a city outside Java.
        foreach ([
            ['32.16', 'Kabupaten Bekasi', 2, '32', null], ['32.16.13', 'Kedungwaringin', 3, '32.16', null],
            ['32.16.13.2001', 'Kedungwaringin', 4, '32.16.13', '17540'], ['32.16.13.2002', 'Karangsambung', 4, '32.16.13', null],
            ['32.75', 'Kota Bekasi', 2, '32', null], ['32.75.01', 'Bekasi Timur', 3, '32.75', null], ['32.75.01.1001', 'Aren Jaya', 4, '32.75.01', '17111'],
            ['73', 'Sulawesi Selatan', 1, null, null], ['73.71', 'Kota Makassar', 2, '73', null], ['73.71.01', 'Mariso', 3, '73.71', null],
            ['73.71.01.1001', 'Bontorannu', 4, '73.71.01', '90121'],
        ] as [$code, $name, $level, $parent, $postal]) {
            Region::query()->insert(['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent, 'postal_code' => $postal, 'search' => RegionDirectory::searchKey($name)]);
        }
        RegionDirectory::flush();
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    /** Form B: name, WhatsApp, colour, quantity and one address starting at the city. */
    private function form(array $address = [], array $design = [], string $slug = 'pendek'): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Tas Badminton Premium (Form Pendek)', 'slug' => $slug, 'status' => 'active', 'design' => ['title' => 'Order'] + $design,
            'property_id' => $this->bag->id,
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'warna', 'type' => 'select', 'label' => 'Warna', 'required' => true, 'options' => ['Hitam', 'Biru'], 'maps_to' => 'variant'],
                ['key' => 'jumlah', 'type' => 'number', 'label' => 'Jumlah', 'required' => true, 'maps_to' => 'quantity'],
                $address + ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address', 'mode' => 'region',
                    'parts' => ['street', 'kota', 'kecamatan', 'kelurahan', 'kode_pos'], 'part_labels' => ['street' => 'Jalan, RT/RW dan/atau dusun, nomor rumah/patokan'],
                    'required_parts' => ['street', 'kota', 'kecamatan', 'kelurahan'], 'address_options' => ['hide_postal', 'city_first', 'soft_rt_check']],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));
    }

    private function send(array $address, array $extra = [], string $slug = 'pendek', array $more = []): TestResponse
    {
        return $this->post('/f/quicker-sport/'.$slug, ['f' => array_replace(['nama' => 'Rina', 'wa' => '081234567890', 'warna' => 'Hitam', 'jumlah' => '1', 'alamat' => $address], $extra)] + $more,
            ['Accept' => 'application/json']);
    }

    private function kabBekasi(array $override = []): array
    {
        return array_replace(['kota' => '32.16', 'kecamatan' => '32.16.13', 'kelurahan' => '32.16.13.2001', 'kode_pos' => '', 'street' => 'Jl. Mawar No. 10, RT 01/RW 02'], $override);
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->latest('id')->firstOrFail());
    }

    // ---- 1. Hidden postal code -----------------------------------------------------------------------------------

    public function test_a_hidden_postal_code_is_filled_from_the_kelurahan(): void
    {
        $this->form();
        $html = $this->get('/f/quicker-sport/pendek')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<label class="lf-f" data-postal-fallback hidden>.*?name="f\[alamat\]\[kode_pos\]"/s', $html, 'kept as a hidden fallback');

        $this->send($this->kabBekasi())->assertOk();
        $lead = $this->lead();
        $this->assertSame('17540', $lead->shipping_address_parts['kode_pos']);
        $this->assertStringEndsWith('Jawa Barat 17540', $lead->shipping_address);

        // A postal code the browser left behind for another village is not trusted: the kelurahan's wins.
        $this->send($this->kabBekasi(['kode_pos' => '99999']), ['wa' => '081234567891'])->assertOk();
        $this->assertSame('17540', $this->lead()->shipping_address_parts['kode_pos']);
    }

    public function test_the_postal_code_is_filled_even_when_the_box_is_not_shown_at_all(): void
    {
        // Finding (a): an older field without the postal code box still gets the kelurahan's code.
        $this->form(['parts' => ['street', 'kota', 'kecamatan', 'kelurahan'], 'address_options' => ['city_first']]);
        $this->send($this->kabBekasi())->assertOk();
        $this->assertSame('17540', $this->lead()->shipping_address_parts['kode_pos']);
        $this->assertStringEndsWith('Jawa Barat 17540', $this->lead()->shipping_address);
    }

    public function test_the_fallback_box_is_required_when_the_kelurahan_has_no_postal_code(): void
    {
        $this->form();
        $this->send($this->kabBekasi(['kelurahan' => '32.16.13.2002']))->assertStatus(422)->assertJsonValidationErrors(['f.alamat']);
        $this->assertSame(0, $this->in(fn () => Lead::query()->count()));

        $this->send($this->kabBekasi(['kelurahan' => '32.16.13.2002', 'kode_pos' => '17541']))->assertOk();
        $this->assertSame('17541', $this->lead()->shipping_address_parts['kode_pos']);
    }

    public function test_without_dropdowns_the_postal_code_box_shows_as_usual(): void
    {
        // Typed addresses (regions not imported, or the field in "ketik bebas"): nothing is hidden.
        $this->form(['mode' => null, 'parts' => ['street', 'kota', 'kecamatan', 'kelurahan', 'kode_pos'], 'required_parts' => ['street', 'kota']]);
        $html = $this->get('/f/quicker-sport/pendek')->assertOk()->getContent();
        $this->assertStringNotContainsString('<label class="lf-f" data-postal-fallback', $html);
        $this->assertStringContainsString('name="f[alamat][kode_pos]"', $html);
    }

    // ---- 2. Dropdowns from the city, province derived -------------------------------------------------------------

    public function test_the_dropdowns_start_at_the_city_and_list_every_city_with_its_province(): void
    {
        $this->form();
        $html = $this->get('/f/quicker-sport/pendek')->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'class="rg-q"'), 'city, district, village');
        $this->assertStringNotContainsString('data-part="provinsi"', $html);
        $this->assertStringContainsString('data-load="'.url('/wilayah/semua-kota').'"', $html);

        $cities = collect($this->getJson('/wilayah/semua-kota')->assertOk()->assertHeader('ETag')->json('items'));
        $this->assertSame('Kabupaten Bekasi (Jawa Barat)', $cities->firstWhere(0, '32.16')[1]);
        $this->assertSame('Kota Bekasi (Jawa Barat)', $cities->firstWhere(0, '32.75')[1]);
        $this->assertSame('Kota Administrasi Jakarta Selatan (DKI Jakarta)', $cities->firstWhere(0, '31.74')[1]);
        $this->assertStringContainsString('kab', $cities->firstWhere(0, '32.16')[3], '"kab bekasi" finds it');
        $this->assertStringContainsString('jaksel', $cities->firstWhere(0, '31.74')[3]);
        $this->assertStringContainsString('max-age=86400', (string) $this->get('/wilayah/semua-kota')->headers->get('Cache-Control'));
    }

    public function test_picking_kab_bekasi_saves_jawa_barat_as_the_province(): void
    {
        $this->form();
        $this->send($this->kabBekasi())->assertOk();
        $lead = $this->lead();

        $this->assertSame('Jawa Barat', $lead->shipping_province);
        $this->assertSame('Jawa Barat', $lead->shipping_address_parts['provinsi']);
        $this->assertSame(['provinsi' => '32', 'kota' => '32.16', 'kecamatan' => '32.16.13', 'kelurahan' => '32.16.13.2001'], $lead->shipping_address_parts['codes']);
        $this->assertSame('Jl. Mawar No. 10, RT 01/RW 02, Desa Kedungwaringin, Kec. Kedungwaringin, Kabupaten Bekasi, Jawa Barat 17540', $lead->shipping_address);
    }

    public function test_twin_city_names_are_not_mixed_up_and_the_chain_must_fit(): void
    {
        $this->form();
        $this->send(['kota' => '32.75', 'kecamatan' => '32.75.01', 'kelurahan' => '32.75.01.1001', 'street' => 'Jl. Melati, RT 03/RW 04'])->assertOk();
        $this->assertSame('Kota Bekasi', $this->lead()->shipping_address_parts['kota']);
        $this->assertSame('17111', $this->lead()->shipping_address_parts['kode_pos']);

        // Kota Bekasi with a district of Kabupaten Bekasi; a city that does not exist.
        $this->send($this->kabBekasi(['kota' => '32.75']), ['wa' => '081234567892'])->assertStatus(422)->assertJsonValidationErrors(['f.alamat']);
        $this->send($this->kabBekasi(['kota' => '32.99', 'kecamatan' => '', 'kelurahan' => '']), ['wa' => '081234567893'])->assertStatus(422)
            ->assertJsonFragment(['Pilihan kota/kabupaten tidak dikenal. Pilih dari daftar.']);
    }

    public function test_older_forms_keep_the_province_dropdown(): void
    {
        $this->form(['address_options' => ['hide_postal'], 'parts' => ['street', 'provinsi', 'kota', 'kecamatan', 'kelurahan', 'kode_pos'], 'required_parts' => ['street', 'kelurahan']]);
        $html = $this->get('/f/quicker-sport/pendek')->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, 'class="rg-q"'));
        $this->assertStringNotContainsString('data-load=', $html);
        $this->send($this->kabBekasi(['kota' => '32.16']))->assertStatus(422)->assertJsonValidationErrors(['f.alamat.provinsi']);
    }

    public function test_the_order_summary_and_shipping_zone_use_the_derived_province(): void
    {
        $this->form();
        $this->assertStringContainsString('data-quote="'.url('/f/quicker-sport/pendek/ringkasan').'"', $this->get('/f/quicker-sport/pendek')->getContent());

        // Outside Java and Bali: the product's shipping promo comes off.
        $makassar = $this->getJson('/f/quicker-sport/pendek/ringkasan?'.http_build_query(['alamat' => ['kota' => '73.71', 'kecamatan' => '73.71.01', 'kelurahan' => '73.71.01.1001'], 'jumlah' => 2]))
            ->assertOk()->json();
        $this->assertTrue($makassar['ok']);
        $this->assertSame([199000, 2, 398000], [$makassar['harga'], $makassar['jumlah'], $makassar['subtotal']]);
        $this->assertSame($makassar['ongkir_normal'] - 30000, $makassar['ongkir']);
        $this->assertSame(398000 + $makassar['ongkir'], $makassar['total']);
        $this->assertStringContainsString('luar Jawa & Bali', $makassar['promo']);
        $this->assertSame('Rp 398.000', $makassar['subtotal_teks']);

        $bekasi = $this->getJson('/f/quicker-sport/pendek/ringkasan?'.http_build_query(['alamat' => ['kota' => '32.16'], 'jumlah' => 1]))->assertOk()->json();
        $this->assertArrayNotHasKey('promo', $bekasi);
        $this->assertLessThan($makassar['ongkir_normal'], $bekasi['ongkir'], 'Jabodetabek zone');

        $this->assertArrayHasKey('pesan', $this->getJson('/f/quicker-sport/pendek/ringkasan?jumlah=1')->json(), 'no city yet: price only');

        $this->send(['kota' => '73.71', 'kecamatan' => '73.71.01', 'kelurahan' => '73.71.01.1001', 'street' => 'Jl. Nusantara RT 2'])->assertOk();
        $this->assertSame('Sulawesi Selatan', $this->lead()->shipping_province);
    }

    public function test_the_provider_location_still_maps_and_a_different_province_is_noted(): void
    {
        $this->form();
        $this->send($this->kabBekasi())->assertOk();
        $parts = (array) $this->lead()->shipping_address_parts;

        // What the Everpro mapping keys on is all there (postal code, kelurahan, kecamatan, kota).
        $this->assertSame('17540|kedungwaringin|kedungwaringin|bekasi', ProviderLocations::regionKey($parts));
        $this->assertNotNull($this->in(fn () => ProviderLocations::key($this->lead())));

        $place = fn (?string $province) => new CarrierLocation('9', 'Kedungwaringin', 'Kedungwaringin', 'Bekasi', $province, '17540');
        $this->assertNull(ProviderLocations::provinceDifference($parts, $place('JAWA BARAT')));
        $this->assertNull(ProviderLocations::provinceDifference($parts, $place(null)));
        $this->assertSame('provinsi Everpro "Jawa Tengah" berbeda dari provinsi kita "Jawa Barat"', ProviderLocations::provinceDifference($parts, $place('Jawa Tengah')));
    }

    // ---- 3. Quantity ----------------------------------------------------------------------------------------------

    public function test_the_quantity_starts_at_one_and_must_be_a_whole_number(): void
    {
        $this->form();
        $html = $this->get('/f/quicker-sport/pendek')->getContent();
        $this->assertMatchesRegularExpression('/name="f\[jumlah\]" value="1" inputmode="numeric"[^>]*min="1"/', $html);
        $this->assertStringContainsString('data-qty="1"', $html);

        $this->send($this->kabBekasi(), ['jumlah' => '0'])->assertStatus(422)->assertJsonValidationErrors(['f.jumlah']);
        $this->send($this->kabBekasi(), ['jumlah' => '1.5'])->assertStatus(422)->assertJsonValidationErrors(['f.jumlah']);
        $this->send($this->kabBekasi(), ['jumlah' => '3'])->assertOk();
        $this->assertSame(3, $this->lead()->quantity);
    }

    // ---- 4. RT/RW | dusun ----------------------------------------------------------------------------------------

    private function form4(): LeadForm
    {
        return $this->form([
            'parts' => ['street', 'rt_rw', 'dusun', 'house', 'landmark', 'provinsi', 'kota', 'kecamatan', 'kelurahan', 'kode_pos'],
            'required_parts' => ['street', 'rt_rw', 'dusun', 'kelurahan'], 'rules' => ['rt_or_dusun', 'landmark_if_no_rt'], 'address_options' => ['hide_postal', 'rt_choice'],
        ], slug: 'empat');
    }

    private function bogor(array $override = []): array
    {
        return array_replace(['provinsi' => '32', 'kota' => '32.01', 'kecamatan' => '32.01.02', 'kelurahan' => '32.01.02.2001', 'street' => 'Jl. Raya Mercedes'], $override);
    }

    public function test_the_address_asks_rt_rw_or_dusun_and_a_dusun_needs_a_house_number_or_landmark(): void
    {
        $this->form4();
        $html = $this->get('/f/quicker-sport/empat')->getContent();
        $this->assertMatchesRegularExpression('/name="f\[alamat\]\[rt_kind\]" value="rt"\s+checked/', $html);
        $this->assertStringContainsString('name="f[alamat][rt_kind]" value="dusun"', $html);
        $this->assertMatchesRegularExpression('/data-rtk="dusun"\s+hidden/', $html);

        // Dusun chosen: the RT/RW left in the hidden box does not count, so a house number or landmark is needed.
        $this->send($this->bogor(['rt_kind' => 'dusun', 'dusun' => 'Krajan', 'rt_rw' => '003/004']), slug: 'empat')->assertStatus(422)
            ->assertJsonFragment([AddressCheck::MESSAGE]);
        $this->send($this->bogor(['rt_kind' => 'dusun', 'dusun' => 'Krajan', 'rt_rw' => '003/004', 'landmark' => 'dekat masjid']), slug: 'empat')->assertOk();
        $lead = $this->lead();
        $this->assertArrayNotHasKey('rt_rw', $lead->shipping_address_parts);
        $this->assertStringContainsString('Dusun Krajan, (dekat masjid)', $lead->shipping_address);
        $this->assertSame('16965', $lead->shipping_address_parts['kode_pos'], 'hidden postal code on form 4 too');

        // RT/RW chosen: zeros are no RT/RW; a real one needs nothing else, and a stale dusun is dropped.
        $this->send($this->bogor(['rt_kind' => 'rt', 'rt_rw' => '000/000']), ['wa' => '081234567891'], slug: 'empat')->assertStatus(422)->assertJsonValidationErrors(['f.alamat']);
        $this->send($this->bogor(['rt_kind' => 'rt', 'rt_rw' => '0']), ['wa' => '081234567891'], slug: 'empat')->assertStatus(422);
        $this->send($this->bogor(['rt_kind' => 'rt', 'rt_rw' => '001/002', 'dusun' => 'Krajan']), ['wa' => '081234567891'], slug: 'empat')->assertOk();
        $this->assertSame('001/002', $this->lead()->shipping_address_parts['rt_rw']);
        $this->assertArrayNotHasKey('dusun', $this->lead()->shipping_address_parts);
    }

    // ---- 5. Soft RT/RW check on form 8 ----------------------------------------------------------------------------

    public function test_an_address_without_rt_rw_or_dusun_is_kept_but_marked_incomplete(): void
    {
        $this->form();
        $html = $this->get('/f/quicker-sport/pendek')->getContent();
        $this->assertStringContainsString(FormFields::SOFT_RT_HINT, $html);
        $this->assertStringContainsString('data-soft-rt', $html);

        $this->send($this->kabBekasi(['street' => 'Jl. Mawar No. 10']))->assertOk();
        $lead = $this->lead();
        $this->assertSame('Tambahkan RT/RW atau dusun', $lead->custom_fields[FormFields::INCOMPLETE_KEY]);
        $this->assertStringContainsString('Alamat belum lengkap: Tambahkan RT/RW atau dusun', $this->in(fn () => $lead->activities()->where('body', 'like', 'Jawaban formulir%')->value('body')));
        $this->assertStringContainsString('Alamat belum lengkap: Tambahkan RT/RW atau dusun', (new BuyerFacts($lead))->instructions(), 'the AI asks for it');
        $this->assertContains('alamat belum lengkap (tambahkan rt/rw atau dusun)', $this->in(fn () => app(AutoShipments::class)->problems($lead)), 'resi otomatis waits');

        // Fixed in the chat or by an agent: the mark goes.
        $this->in(fn () => $lead->update(['shipping_address' => 'Jl. Mawar No. 10 RT 02/RW 05, Desa Kedungwaringin, Kec. Kedungwaringin, Kabupaten Bekasi, Jawa Barat 17540']));
        $this->assertArrayNotHasKey(FormFields::INCOMPLETE_KEY, (array) $lead->fresh()->custom_fields);

        foreach (['Jl. Mawar No. 10 RT 01/RW 02', 'Jl. Mawar RT/RW 02/03 no 4', 'Dusun Krajan No. 3', 'Kp. Cibeber dekat masjid', 'Dsn Sukamaju rt01'] as $i => $street) {
            $this->send($this->kabBekasi(['street' => $street]), ['wa' => '08123456780'.$i])->assertOk();
            $this->assertArrayNotHasKey(FormFields::INCOMPLETE_KEY, (array) $this->lead()->custom_fields, $street);
        }
    }

    // ---- 6. A/B test ------------------------------------------------------------------------------------------------

    public function test_the_ab_address_serves_both_pages_and_records_the_variant(): void
    {
        $a = $this->form([], [], 'panjang');
        $b = $this->form();
        [$pageA, $pageB] = $this->in(fn () => [
            LandingPage::create(['title' => 'Tas A', 'slug' => 'tas-badminton-premium', 'status' => 'published', 'blocks' => [['type' => 'form', 'data' => ['form_id' => $a->id, 'heading' => 'Pesan A']]]]),
            LandingPage::create(['title' => 'Tas B', 'slug' => 'tas-badminton-premium-b', 'status' => 'draft', 'blocks' => [['type' => 'form', 'data' => ['form_id' => $b->id, 'heading' => 'Pesan B']]]]),
        ]);

        // No test yet: ?v=auto is just page A.
        $this->assertStringNotContainsString('name="ab"', $this->get('/p/quicker-sport/tas-badminton-premium?v=auto')->assertOk()->getContent());
        $this->in(fn () => $pageA->update(['ab_test' => ['enabled' => true, 'partner_id' => $pageB->id, 'spend_b' => '100000']]));

        $html = $this->get('/p/quicker-sport/tas-badminton-premium?v=b')->assertOk()->getContent();
        $this->assertStringContainsString('Pesan B', $html, 'page B shows although it is a draft');
        $this->assertStringContainsString('<input type="hidden" name="ab" value="B">', $html);
        $this->assertStringContainsString('Pesan A', $this->get('/p/quicker-sport/tas-badminton-premium?v=a')->getContent());
        $this->assertStringNotContainsString('name="ab"', $this->get('/p/quicker-sport/tas-badminton-premium')->getContent(), 'the plain address is page A, outside the test');
        $this->get('/p/quicker-sport/tas-badminton-premium-b')->assertNotFound();

        $isB = fn () => str_contains($this->get('/p/quicker-sport/tas-badminton-premium?v=auto')->getContent(), 'name="ab" value="B"');
        $first = $isB();
        $this->assertSame($first, $isB(), 'the same visitor keeps their variant');
        $this->assertSame(1 + ($first ? 2 : 0), $pageB->fresh()->ab_views);

        $this->send($this->kabBekasi(), [], 'pendek', ['page' => $pageB->id, 'ab' => 'B', 'utm_source' => 'meta'])->assertOk();
        $lead = $this->lead();
        $this->assertSame(['B', $pageB->id, $b->id], [$lead->ab_variant, $lead->landing_page_id, $lead->lead_form_id]);
        $this->assertSame(['utm_source' => 'meta', 'ab_variant' => 'B'], $lead->custom_fields['kampanye']);

        // A variant that does not fit the page is not recorded.
        $this->send($this->kabBekasi(), ['wa' => '081234567899'], 'panjang', ['page' => $pageA->id, 'ab' => 'B'])->assertOk();
        $this->assertNull($this->lead()->ab_variant);

        $report = collect($this->in(fn () => AbTest::report($pageA->fresh())))->keyBy('variant');
        $this->assertSame([0, 0], [$report['A']['leads'], $report['A']['closings']]);
        $this->assertSame(1, $report['B']['leads']);
        $this->assertSame(100000, $report['B']['spend']);
        $this->assertNull($report['B']['cost_per_closing'], 'no closing yet');
        $this->assertSame(round(1 / $pageB->fresh()->ab_views * 100, 1), $report['B']['rate']);
    }

    // ---- 7. Two steps ---------------------------------------------------------------------------------------------

    public function test_a_two_step_form_keeps_the_lead_at_step_one_and_completes_it_at_step_two(): void
    {
        $this->form(design: ['two_step' => true]);
        $html = $this->get('/f/quicker-sport/pendek')->getContent();
        $this->assertStringContainsString('data-steps="2"', $html);
        $this->assertStringContainsString('Langkah 1/2', $html);
        $this->assertLessThan(strpos($html, 'data-step="2"'), strpos($html, 'name="f[jumlah]"'));
        $this->assertGreaterThan(strpos($html, 'data-step="2"'), strpos($html, 'name="f[alamat][street]"'));

        // Step 1: only name, WhatsApp, colour and quantity are checked.
        $this->post('/f/quicker-sport/pendek', ['step' => '1', 'f' => ['nama' => 'Rina', 'wa' => '081234567890', 'warna' => 'Biru', 'jumlah' => '2']], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['ok' => true, 'step' => 2]);
        $lead = $this->lead();
        $this->assertSame(['Rina', 'Biru', 2], [$lead->name, $lead->variant, $lead->quantity]);
        $this->assertNull($lead->shipping_address);
        $this->assertSame(FormFields::STEP_ONE_MESSAGE, $lead->custom_fields[FormFields::INCOMPLETE_KEY]);
        $this->assertSame(0, $this->in(fn () => LeadForm::query()->where('slug', 'pendek')->value('submissions')), 'not a submission yet');

        $this->send($this->kabBekasi(), ['warna' => 'Biru', 'jumlah' => '2'])->assertOk();
        $this->assertSame(1, $this->in(fn () => Lead::query()->count()), 'the same lead');
        $lead = $this->lead();
        $this->assertStringContainsString('Kabupaten Bekasi', $lead->shipping_address);
        $this->assertArrayNotHasKey(FormFields::INCOMPLETE_KEY, (array) $lead->custom_fields);
        $this->assertArrayNotHasKey('formulir_langkah_1', (array) $lead->custom_fields);
        $this->assertSame($lead->lead_form_id, $this->in(fn () => LeadForm::query()->where('slug', 'pendek')->value('id')));

        // A form without the setting ignores step=1 and checks everything.
        $this->form(slug: 'satu');
        $this->post('/f/quicker-sport/satu', ['step' => '1', 'f' => ['nama' => 'Budi', 'wa' => '081298765432', 'warna' => 'Biru', 'jumlah' => '1']], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['f.alamat']);
    }

    // ---- 8/9. Phone layout, summary and success ---------------------------------------------------------------------

    public function test_the_form_is_laid_out_for_phones_and_says_where_the_buyer_goes_next(): void
    {
        $this->form();
        $html = $this->get('/f/quicker-sport/pendek')->getContent();
        $this->assertStringContainsString('autocomplete=name', $html);
        $this->assertStringContainsString('inputmode=tel autocomplete=tel', $html);
        $this->assertStringContainsString('autocomplete="address-line1"', $html);
        $this->assertStringContainsString('.lf-send, .lf-next { position: sticky', $html);
        $this->assertStringContainsString('data-sum hidden', $html);
        $this->assertStringContainsString('Anda dialihkan ke WhatsApp', $html);
    }
}
