<?php

namespace Tests\Feature;

use App\Actions\GenerateAiReply;
use App\Actions\ProvisionTenant;
use App\Filament\Resources\LeadForms\Pages\EditLeadForm;
use App\LeadForms\FormFields;
use App\LeadForms\FormTemplates;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The fields of a built form do what the owner set: hidden address sub-fields stay hidden, "Wajib diisi" on and off
 * is respected everywhere, the "salah satu wajib" rules, and a payment field that is optional and mapped per choice.
 */
class FormCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function form(array $fields, array $after = ['type' => 'message', 'message' => 'Terima kasih.']): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Order', 'slug' => 'order', 'status' => 'active', 'design' => ['title' => 'Order'],
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ...$fields,
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => $after,
        ]));
    }

    private function address(array $extra = []): array
    {
        return $extra + ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address'];
    }

    private function send(array $answers): TestResponse
    {
        return $this->postJson('/f/toko-uji/order', ['f' => $answers + ['nama' => 'Rina', 'wa' => '081234567890']]);
    }

    private function html(): string
    {
        return $this->get('/f/toko-uji/order')->assertOk()->getContent();
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->with('stage')->latest('id')->firstOrFail());
    }

    private function import(): void
    {
        $this->assertSame(0, Artisan::call('regions:import', [
            '--wilayah' => base_path('tests/fixtures/regions/wilayah.sql'),
            '--kodepos' => base_path('tests/fixtures/regions/wilayah_kodepos.sql'),
        ]));
    }

    // ---- A1: hidden sub-fields ------------------------------------------------------------------------------------

    public function test_hidden_address_sub_fields_are_not_shown_or_saved_when_typed(): void
    {
        $this->form([$this->address(['parts' => ['kota', 'street'], 'required_parts' => ['street']])]);
        $html = $this->html();

        $this->assertStringContainsString('name="f[alamat][street]"', $html);
        $this->assertStringContainsString('name="f[alamat][kota]"', $html);
        $this->assertLessThan(strpos($html, 'name="f[alamat][street]"'), strpos($html, 'name="f[alamat][kota]"'), 'the owner\'s order is kept');
        foreach (['kecamatan', 'kelurahan', 'rt_rw', 'landmark', 'provinsi', 'kode_pos', 'dusun', 'house'] as $part) {
            $this->assertStringNotContainsString('name="f[alamat]['.$part.']"', $html);
        }

        $this->send(['alamat' => ['street' => 'Jl. Mawar 5', 'kota' => 'Bekasi', 'kecamatan' => 'Tambun']])->assertOk();
        $this->assertSame('Jl. Mawar 5, Bekasi', $this->lead()->shipping_address);
    }

    public function test_region_dropdowns_may_stop_at_any_level_but_never_skip_one(): void
    {
        $this->import();
        $this->form([$this->address(['mode' => 'region', 'parts' => ['provinsi', 'kota', 'kecamatan', 'street'], 'required_parts' => ['kecamatan', 'street']])]);
        $html = $this->html();

        $this->assertSame(3, substr_count($html, 'class="rg-q"'), 'no village picker');
        $this->assertStringNotContainsString('data-part="kelurahan"', $html);
        $this->assertStringNotContainsString('name="f[alamat][kode_pos]"', $html);

        // The district is required, so the levels above it are too.
        $this->send(['alamat' => ['kecamatan' => '32.01.02', 'street' => 'Jl. Raya']])->assertStatus(422)->assertJsonValidationErrors(['f.alamat.provinsi', 'f.alamat.kota']);
        $this->send(['alamat' => ['provinsi' => '32', 'kota' => '32.01', 'kecamatan' => '32.01.02', 'street' => 'Jl. Raya', 'kelurahan' => '32.01.02.2001']])->assertOk();
        $this->assertSame('Jl. Raya, Kec. Gunungputri, Kabupaten Bogor, Jawa Barat', $this->lead()->shipping_address, 'a village sent anyway is ignored');

        // A district without its city is a gap: the editor refuses it and the form leaves the levels below the gap out.
        $gap = $this->address(['mode' => 'region', 'parts' => ['provinsi', 'kecamatan', 'street']]);
        $this->assertNotEmpty(array_filter(FormFields::problems([$gap]), fn ($p) => str_contains($p, 'tidak boleh melompat')));
        $this->assertSame(['provinsi', 'street'], FormFields::addressParts(FormFields::normalize([$gap])[0]));
    }

    // ---- A2 / A3: "Wajib diisi" -----------------------------------------------------------------------------------

    public function test_an_optional_address_has_no_required_sub_field_even_with_ticks(): void
    {
        $this->form([$this->address(['required' => false, 'required_parts' => ['street', 'kecamatan', 'kota']])]);
        $html = $this->html();

        $this->assertDoesNotMatchRegularExpression('/name="f\[alamat\]\[[a-z_]+\]"[^>]*required/', $html);
        $this->assertStringNotContainsString('Kecamatan *', $html);
        $this->send([])->assertOk();
        $this->send(['alamat' => ['street' => '', 'kota' => 'Bekasi']])->assertOk();
        $this->assertSame([], FormFields::requiredParts(FormFields::normalize([$this->address(['required' => false])])[0]));
    }

    public function test_a_required_address_uses_exactly_the_ticked_sub_fields_and_none_when_unticked(): void
    {
        $this->form([$this->address(['required_parts' => ['kota']])]);
        $this->assertStringContainsString('Kota / kabupaten *', $this->html());
        $this->assertStringNotContainsString('Alamat (jalan, blok) *', $this->html());
        $this->send(['alamat' => ['street' => 'Jl. Mawar']])->assertStatus(422)->assertJsonValidationErrors(['f.alamat.kota']);
        $this->send(['alamat' => ['kota' => 'Bekasi']])->assertOk();

        // Every tick removed: stays empty, not the old default.
        $this->in(fn () => LeadForm::query()->delete());
        $this->form([$this->address(['required_parts' => []])]);
        $field = $this->in(fn () => LeadForm::query()->sole()->fieldList()[2]);
        $this->assertSame([], $field['required_parts']);
        $this->assertSame([], FormFields::requiredParts($field));
        $this->send(['alamat' => ['street' => 'Jl. Mawar']])->assertOk();

        // A field saved before the list could be emptied keeps the old default.
        $this->assertSame(FormFields::ADDRESS_REQUIRED, FormFields::requiredParts(['type' => 'address', 'required' => true, 'parts' => FormFields::LEGACY_PARTS]));
    }

    public function test_required_on_and_off_is_respected_for_every_field_type(): void
    {
        $types = [
            ['key' => 'teks', 'type' => 'text', 'label' => 'Teks'],
            ['key' => 'panjang', 'type' => 'textarea', 'label' => 'Panjang'],
            ['key' => 'surel', 'type' => 'email', 'label' => 'Surel'],
            ['key' => 'angka', 'type' => 'number', 'label' => 'Angka'],
            ['key' => 'hp', 'type' => 'phone', 'label' => 'HP lain'],
            ['key' => 'pilih', 'type' => 'select', 'label' => 'Pilih', 'options' => ['A', 'B']],
            ['key' => 'radio', 'type' => 'radio', 'label' => 'Radio', 'options' => ['A', 'B']],
            ['key' => 'centang', 'type' => 'checkbox', 'label' => 'Centang', 'options' => ['A', 'B']],
            ['key' => 'setuju', 'type' => 'checkbox', 'label' => 'Setuju'],
            ['key' => 'tanggal', 'type' => 'date', 'label' => 'Tanggal'],
        ];

        $this->form(array_map(fn ($f) => $f + ['required' => false], $types));
        $html = $this->html();
        foreach ($types as $f) {
            $this->assertDoesNotMatchRegularExpression('/name="f\['.$f['key'].'\](\[\])?"[^>]*required/', $html, $f['type'].' off: no required attribute');
            $this->assertStringNotContainsString($f['label'].' *', $html);
        }
        $this->send([])->assertOk();

        $this->in(fn () => LeadForm::query()->delete());
        $this->form(array_map(fn ($f) => $f + ['required' => true], $types));
        $html = $this->html();
        foreach ($types as $f) {
            $this->assertStringContainsString($f['label'].' *', $html, $f['type'].' on: marked');
        }
        $this->send([])->assertStatus(422)->assertJsonValidationErrors(array_map(fn ($f) => 'f.'.$f['key'], $types));
    }

    // ---- B: sub-field customization -----------------------------------------------------------------------------

    public function test_sub_fields_take_their_own_labels_placeholders_and_dusun_joins_the_saved_address(): void
    {
        $this->form([$this->address([
            'parts' => ['street', 'house', 'dusun', 'kota'], 'required_parts' => ['street'],
            'part_labels' => ['dusun' => 'Kampung'], 'part_placeholders' => ['street' => 'Nama jalan saja'],
        ])]);
        $html = $this->html();

        $this->assertStringContainsString('<span>Kampung</span>', $html);
        $this->assertStringContainsString('placeholder="Nama jalan saja"', $html);
        $this->assertStringContainsString('<span>Nomor rumah</span>', $html);

        $this->send(['alamat' => ['street' => 'Jl. Kenanga', 'house' => '7B', 'dusun' => 'Krajan', 'kota' => '']])->assertOk();
        $this->assertSame('Jl. Kenanga, No. 7B, Dusun Krajan', $this->lead()->shipping_address, 'only the filled sub-fields');
        $this->assertSame(['street' => 'Jl. Kenanga', 'house' => '7B', 'dusun' => 'Krajan'], $this->lead()->shipping_address_parts);
    }

    public function test_rt_rw_or_dusun_and_house_or_landmark_rules(): void
    {
        $this->form([$this->address(['parts' => array_keys(FormFields::ADDRESS_PARTS), 'required_parts' => ['street'], 'rules' => ['rt_or_dusun', 'house_or_landmark']])]);
        $html = $this->html();
        $this->assertStringContainsString('data-oneof=rt_or_dusun', $html);
        $this->assertStringContainsString('Isi RT/RW atau dusun/kampung.', $html);

        $this->send(['alamat' => ['street' => 'Jl. A']])->assertStatus(422)
            ->assertJsonPath('errors', fn ($e) => $e['f.alamat'] === ['Isi RT/RW atau dusun/kampung.', 'Isi nomor rumah atau patokan.']);
        $this->send(['alamat' => ['street' => 'Jl. A', 'dusun' => 'Dusun Sukamaju', 'landmark' => 'depan masjid']])->assertOk();
        $this->assertSame('Jl. A, Dusun Sukamaju, (depan masjid)', $this->lead()->shipping_address);
        $this->send(['wa' => '081200001111', 'alamat' => ['street' => 'Jl. B', 'rt_rw' => '01/02', 'house' => '9']])->assertOk();

        // Switched off, or on an optional address: nothing is enforced.
        $this->in(fn () => LeadForm::query()->delete());
        $this->form([$this->address(['required' => false, 'rules' => ['rt_or_dusun']])]);
        $this->send(['wa' => '081200002222', 'alamat' => ['street' => 'Jl. C']])->assertOk();
        $this->in(fn () => LeadForm::query()->delete());
        $this->form([$this->address(['parts' => array_keys(FormFields::ADDRESS_PARTS), 'required_parts' => ['street']])]);
        $this->send(['wa' => '081200003333', 'alamat' => ['street' => 'Jl. D']])->assertOk();
    }

    public function test_the_order_template_shows_every_sub_field_with_both_rules_and_the_editor_keeps_changes(): void
    {
        $address = collect(FormTemplates::attributes(FormTemplates::ORDER)['fields'])->firstWhere('key', 'alamat');
        $this->assertSame(array_keys(FormFields::ADDRESS_PARTS), $address['parts']);
        $this->assertSame(['rt_or_dusun', 'landmark_if_no_rt'], $address['rules']);

        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
        $form = $this->in(fn () => LeadForm::create(['name' => 'Order', 'slug' => 'order', 'status' => 'active'] + FormTemplates::attributes(FormTemplates::ORDER)));

        $lw = Livewire::test(EditLeadForm::class, ['record' => $form->getKey()]);
        $fields = $lw->get('data.fields');
        $key = collect($fields)->search(fn ($f) => $f['key'] === 'alamat');
        $rows = $fields[$key]['subfields'];
        $this->assertCount(count(FormFields::ADDRESS_PARTS), $rows);

        // Hide the postal code, make "Dusun" required and rename it, untick every other required sub-field, drop a rule.
        foreach ($rows as $i => $row) {
            $rows[$i]['required'] = $row['part'] === 'dusun';
            $rows[$i]['show'] = $row['part'] !== 'kode_pos';
            $rows[$i]['label'] = $row['part'] === 'dusun' ? 'Kampung' : $row['label'];
        }
        $lw->set("data.fields.$key.subfields", $rows)->set("data.fields.$key.rules", ['house_or_landmark'])->call('save')->assertHasNoFormErrors();

        $saved = collect($form->fresh()->fieldList())->firstWhere('key', 'alamat');
        $this->assertNotContains('kode_pos', $saved['parts']);
        $this->assertSame(['dusun'], $saved['required_parts']);
        $this->assertSame(['dusun' => 'Kampung'], $saved['part_labels']);
        $this->assertSame(['house_or_landmark'], $saved['rules']);

        // Saving again without changes keeps everything.
        Livewire::test(EditLeadForm::class, ['record' => $form->getKey()])->call('save')->assertHasNoFormErrors();
        $this->assertSame($saved, collect($form->fresh()->fieldList())->firstWhere('key', 'alamat'));
    }

    // ---- C: payment ---------------------------------------------------------------------------------------------

    public function test_a_form_without_a_payment_field_leaves_payment_empty_and_out_of_every_summary(): void
    {
        $this->form([
            $this->address(['parts' => ['street', 'kota'], 'required_parts' => ['street']]),
            ['key' => 'catatan', 'type' => 'textarea', 'label' => 'Catatan', 'maps_to' => 'note'],
        ], [
            'type' => 'whatsapp', 'wa_target' => 'manual', 'wa_number' => '0812 5555 6666', 'message' => "Terima kasih {nama}!\n{ringkasan}",
            'wa_message' => "Halo admin, saya {nama} mau order:\nPembayaran: {pembayaran}\nAlamat: {alamat}\nCatatan: {catatan}\nTerima kasih.",
        ]);

        $response = $this->send(['alamat' => ['street' => 'Jl. Melati 3', 'kota' => 'Bandung']])->assertOk();
        $text = urldecode(explode('?text=', $response->json('action.url'))[1]);
        $this->assertSame("Halo admin, saya Rina mau order:\nAlamat: Jl. Melati 3, Bandung\nTerima kasih.", $text);
        $this->assertSame("Terima kasih Rina!\nNama: Rina\nWhatsApp: 6281234567890\nAlamat: Jl. Melati 3, Bandung", $response->json('action.message'));

        $lead = $this->lead();
        $this->assertNull($lead->payment_type);
        $this->assertSame('Order masuk', $lead->stage->name, 'an order from a form waits in "Order masuk", never moved by payment');
        $note = $this->in(fn () => $lead->activities()->where('type', 'note')->latest('id')->value('body'));
        $this->assertStringContainsString('Cara bayar belum ditanyakan.', $note);
        $this->assertStringNotContainsString('Pembayaran', $note);
        $this->assertStringNotContainsString('Catatan', $note);

        $this->assertTrue(GenerateAiReply::paymentNotAsked($lead), 'the AI is told to ask how they pay');
        $lead->payment_type = 'cod';
        $this->assertFalse(GenerateAiReply::paymentNotAsked($lead));
    }

    public function test_payment_choices_map_to_their_own_kind(): void
    {
        $this->form([
            $this->address(['parts' => ['street'], 'required_parts' => ['street']]),
            ['key' => 'bayar', 'type' => 'radio', 'label' => 'Cara bayar', 'required' => true, 'maps_to' => 'payment_type', 'payment_choices' => [
                ['label' => 'QRIS', 'kind' => 'other'], ['label' => 'Bayar di toko', 'kind' => 'cod'], ['label' => 'Kirim ke rekening', 'kind' => 'transfer'],
            ]],
        ]);
        $field = $this->in(fn () => LeadForm::query()->sole()->fieldList()[3]);
        $this->assertSame(['QRIS', 'Bayar di toko', 'Kirim ke rekening'], $field['options']);
        $this->assertSame(['QRIS' => 'other', 'Bayar di toko' => 'cod', 'Kirim ke rekening' => 'transfer'], $field['payment_map']);

        $n = 0;
        foreach (['QRIS' => 'other', 'Bayar di toko' => 'cod', 'Kirim ke rekening' => 'transfer'] as $choice => $kind) {
            $this->send(['wa' => '08120000100'.$n++, 'alamat' => ['street' => 'Jl. A'], 'bayar' => $choice])->assertOk();
            $lead = $this->lead();
            $this->assertSame($kind, $lead->payment_type, $choice);
            $this->assertSame('Order masuk', $lead->stage->name, 'the form never moves a lead on payment');
        }
        $this->send(['wa' => '081200009999', 'alamat' => ['street' => 'Jl. A'], 'bayar' => 'Tunai'])->assertStatus(422);

        // Only COD on offer is fine; an old form without a map is guessed, and an unknown choice is "other", not transfer.
        $this->assertSame(['COD' => 'cod'], FormFields::normalize([['key' => 'b', 'type' => 'select', 'label' => 'B', 'maps_to' => 'payment_type', 'options' => ['COD']]])[0]['payment_map']);
        $old = FormFields::normalize([['key' => 'b', 'type' => 'radio', 'label' => 'B', 'maps_to' => 'payment_type', 'options' => ['Transfer BCA', 'COD', 'OVO']]])[0];
        $this->assertSame(['Transfer BCA' => 'transfer', 'COD' => 'cod', 'OVO' => 'other'], $old['payment_map']);
        $this->assertSame('other', FormFields::paymentKind($old, 'Gopay'));
    }
}
