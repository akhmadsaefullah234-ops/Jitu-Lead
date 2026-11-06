<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\LeadForms\FormFields;
use App\LeadForms\FormTemplates;
use App\Leads\AddressCheck;
use App\Leads\ChatShippingDetails;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Tenant;
use App\Models\User;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A house number or landmark is required only when the address has no RT/RW (a dusun address, or RT/RW 0/000):
 * the address check (App\Leads\AddressCheck) behind Buat resi and the chat extraction, and the form rule
 * "landmark_if_no_rt" on the server and in the public form.
 */
class LandmarkWithoutRtTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private const BASE = ['street' => 'Jl. Raya Pakis', 'kelurahan' => 'Sukamaju', 'kecamatan' => 'Pakis', 'kota' => 'Kab. Malang', 'provinsi' => 'Jawa Timur', 'kode_pos' => '65154'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function lead(array $parts): Lead
    {
        return (new Lead)->forceFill([
            'name' => 'Rina Kartika', 'phone' => '6281234567890',
            'shipping_address' => 'Dusun Krajan, Desa Sukamaju, Kec. Pakis, Kab. Malang 65154', 'shipping_address_parts' => $parts,
        ]);
    }

    public function test_dusun_without_rt_rw_needs_a_house_number_or_landmark_and_rt_rw_does_not(): void
    {
        $dusun = self::BASE + ['dusun' => 'Krajan'];

        // Dusun without RT/RW and without a landmark: belum lengkap
        $this->assertTrue(AddressCheck::needsLandmark($dusun));
        $this->assertSame(AddressCheck::MESSAGE, AddressCheck::problem($dusun));
        $this->assertSame('Isi nomor rumah atau patokan karena alamat tanpa RT/RW.', AddressCheck::MESSAGE);
        foreach (['0', '00', '000', '0/0', '000/000', 'RT 000 RW 000', '-'] as $zero) {
            $this->assertTrue(AddressCheck::needsLandmark($dusun + ['rt_rw' => $zero]), "RT/RW {$zero} is no RT/RW");
        }

        // Dusun with a landmark or a house number: lengkap
        $this->assertFalse(AddressCheck::needsLandmark($dusun + ['landmark' => 'Depan masjid Al Ikhlas']));
        $this->assertFalse(AddressCheck::needsLandmark($dusun + ['house' => '12']));
        $this->assertFalse(AddressCheck::needsLandmark($dusun + ['rt_rw' => '000/000', 'landmark' => 'Pagar hijau']));

        // RT/RW without a landmark: lengkap (house number and landmark optional)
        $this->assertFalse(AddressCheck::needsLandmark(self::BASE + ['rt_rw' => '001/002']));
        $this->assertFalse(AddressCheck::needsLandmark(self::BASE + ['rt_rw' => '01/00']));
        $this->assertNull(AddressCheck::problem(self::BASE + ['rt_rw' => '3/5']));
    }

    public function test_buat_resi_and_the_chat_extraction_mark_a_dusun_address_without_landmark_incomplete(): void
    {
        $shipments = app(Shipments::class);

        $this->in(function () use ($shipments) {
            $noLandmark = $this->lead(self::BASE + ['dusun' => 'Krajan']);
            $this->assertContains(AddressCheck::MESSAGE, $shipments->leadProblems($noLandmark), 'Buat resi asks to complete it');
            $this->assertFalse(ChatShippingDetails::complete($noLandmark));
            $this->assertSame([AddressCheck::MESSAGE], ChatShippingDetails::missing($noLandmark->shipping_address, (array) $noLandmark->shipping_address_parts));

            $zero = $this->lead(self::BASE + ['dusun' => 'Krajan', 'rt_rw' => '000/000']);
            $this->assertContains(AddressCheck::MESSAGE, $shipments->leadProblems($zero));
            $this->assertFalse(ChatShippingDetails::complete($zero));

            $landmark = $this->lead(self::BASE + ['dusun' => 'Krajan', 'landmark' => 'Depan masjid']);
            $this->assertNotContains(AddressCheck::MESSAGE, $shipments->leadProblems($landmark));
            $this->assertTrue(ChatShippingDetails::complete($landmark));
            $this->assertSame([], ChatShippingDetails::missing($landmark->shipping_address, (array) $landmark->shipping_address_parts));

            $inText = (new Lead)->forceFill(['name' => 'Rina', 'phone' => '6281234567890', 'shipping_address' => 'Jl. Mawar RT 001/002, Sekeloa, Coblong, Kota Bandung 40134',
                'shipping_address_parts' => ['street' => 'Jl. Mawar', 'kelurahan' => 'Sekeloa', 'kecamatan' => 'Coblong', 'kode_pos' => '40134']]);
            $this->assertSame([], $shipments->leadProblems($inText), 'the RT/RW written only in the address text counts');
            $this->assertTrue(ChatShippingDetails::complete($inText));

            $rtRw = $this->lead(self::BASE + ['rt_rw' => '001/002']);
            $this->assertSame([], $shipments->leadProblems($rtRw), 'RT/RW without landmark never holds the resi');
            $this->assertTrue(ChatShippingDetails::complete($rtRw));
        });
    }

    public function test_the_house_number_typed_apart_from_the_address_reaches_the_courier_note(): void
    {
        $this->assertSame('No. 12, Depan masjid', Shipments::addressNote($this->lead(self::BASE + ['house' => '12', 'landmark' => 'Depan masjid'])));
        $this->assertSame('Pagar hijau', Shipments::addressNote((new Lead)->forceFill(['shipping_address' => 'Jl. Mawar No. 5', 'shipping_address_parts' => ['house' => '5', 'landmark' => 'Pagar hijau']])));
        $this->assertSame('No. 5', Shipments::addressNote((new Lead)->forceFill(['shipping_address' => 'Jl. Mawar No. 50', 'shipping_address_parts' => ['house' => '5']])));
        $this->assertNull(Shipments::addressNote($this->lead(self::BASE + ['rt_rw' => '001/002'])));
    }

    // ---- The form rule "landmark_if_no_rt" ---------------------------------------------------------------------

    private function form(array $address): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Order', 'slug' => 'order', 'status' => 'active', 'design' => ['title' => 'Order'],
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                $address + ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address'],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));
    }

    private int $phone = 0;

    private function send(array $address): TestResponse
    {
        return $this->postJson('/f/toko-uji/order', ['f' => ['nama' => 'Rina', 'wa' => '08123456789'.$this->phone++, 'alamat' => $address]]);
    }

    public function test_the_form_rule_requires_a_house_number_or_landmark_only_without_rt_rw(): void
    {
        $this->form(['parts' => array_keys(FormFields::ADDRESS_PARTS), 'required_parts' => ['street'], 'rules' => ['rt_or_dusun', 'landmark_if_no_rt']]);

        $html = $this->get('/f/toko-uji/order')->assertOk()->getContent();
        $this->assertStringContainsString('data-oneof=landmark_if_no_rt data-oneof-if=no_rt', $html);
        $this->assertMatchesRegularExpression('/data-oneof-note="landmark_if_no_rt"\s+data-if-note/', $html);
        $this->assertStringContainsString('Isi nomor rumah atau patokan karena alamat tanpa RT/RW.</small>', $html);
        $this->assertStringContainsString('<b class="lf-if" data-if-star> *</b>', $html, 'patokan marked required while RT/RW is empty');
        $this->assertStringContainsString('noRt(box)', $html);

        // Dusun without RT/RW and without house number or landmark: refused
        $this->send(['street' => 'Jl. A', 'dusun' => 'Krajan'])->assertStatus(422)
            ->assertJsonPath('errors', fn ($e) => $e['f.alamat'] === ['Isi nomor rumah atau patokan karena alamat tanpa RT/RW.']);
        foreach (['0', '000', '000/000'] as $zero) {
            $this->send(['street' => 'Jl. A', 'dusun' => 'Krajan', 'rt_rw' => $zero])->assertStatus(422)
                ->assertJsonPath('errors', fn ($e) => $e['f.alamat'] === ['Isi nomor rumah atau patokan karena alamat tanpa RT/RW.']);
        }

        // Dusun with a landmark (or a house number): accepted
        $this->send(['street' => 'Jl. A', 'dusun' => 'Krajan', 'landmark' => 'Depan masjid'])->assertOk();
        $this->send(['street' => 'Jl. A', 'dusun' => 'Krajan', 'rt_rw' => '0', 'house' => '7'])->assertOk();

        // RT/RW without house number or landmark: accepted
        $this->send(['street' => 'Jl. B', 'rt_rw' => '001/002'])->assertOk();
        $this->assertSame(3, $this->in(fn () => Lead::query()->count()));
    }

    public function test_the_rule_needs_a_required_address_and_gives_way_to_house_or_landmark(): void
    {
        $this->assertSame([], FormFields::addressRules(['type' => 'address', 'required' => false, 'parts' => array_keys(FormFields::ADDRESS_PARTS), 'rules' => ['landmark_if_no_rt']]));
        $this->assertSame(['house_or_landmark'], array_keys(FormFields::addressRules(['type' => 'address', 'required' => true, 'parts' => array_keys(FormFields::ADDRESS_PARTS), 'rules' => ['house_or_landmark', 'landmark_if_no_rt']])));
        $this->assertSame([['landmark'], AddressCheck::MESSAGE, true], FormFields::addressRules(['type' => 'address', 'required' => true, 'parts' => ['street', 'rt_rw', 'landmark'], 'rules' => ['landmark_if_no_rt']])['landmark_if_no_rt']);
        $this->assertSame(['landmark_if_no_rt'], FormFields::normalize([['key' => 'a', 'type' => 'address', 'label' => 'A', 'rules' => ['landmark_if_no_rt', 'nope']]])[0]['rules'], 'kept when saved');
    }

    public function test_the_retail_order_template_starts_with_the_rule(): void
    {
        $address = collect(FormTemplates::attributes(FormTemplates::ORDER)['fields'])->firstWhere('key', 'alamat');

        $this->assertSame(['rt_or_dusun', 'landmark_if_no_rt'], $address['rules']);
    }

    public function test_the_migration_turns_the_rule_on_for_the_tas_badminton_form(): void
    {
        $fields = FormFields::normalize([
            ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address', 'parts' => array_keys(FormFields::ADDRESS_PARTS), 'rules' => ['rt_or_dusun', 'house_or_landmark']],
        ]);
        DB::table('lead_forms')->whereIn('id', [4, 900004])->delete();
        foreach ([4 => 'tas-badminton', 900004 => 'lain'] as $id => $slug) {
            DB::table('lead_forms')->insert(['id' => $id, 'tenant_id' => $this->shop->id, 'name' => $slug, 'slug' => $slug, 'status' => 'active',
                'fields' => json_encode($fields), 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration = require database_path('migrations/2026_11_28_000100_add_landmark_if_no_rt_rule_to_badminton_form.php');
        $migration->up();
        $migration->up();

        $rules = fn (int $id) => collect(json_decode(DB::table('lead_forms')->where('id', $id)->value('fields'), true))->firstWhere('key', 'alamat')['rules'] ?? null;
        $this->assertSame(['rt_or_dusun', 'landmark_if_no_rt'], $rules(4));
        $this->assertSame(['rt_or_dusun', 'house_or_landmark'], $rules(900004), 'other forms are left alone');

        $migration->down();
        $this->assertSame(['rt_or_dusun'], $rules(4));
    }
}
