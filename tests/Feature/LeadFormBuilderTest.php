<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\LeadForms\Pages\CreateLeadForm;
use App\Filament\Resources\LeadForms\Pages\EditLeadForm;
use App\Jobs\SendConversionEvents;
use App\LandingPages\Buttons;
use App\LeadForms\FormFields;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Tracking\ConversionPayload;
use App\Tracking\MetaConversions;
use App\Tracking\PixelEvents;
use App\Tracking\TikTokEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LeadFormBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->agent = $this->member($this->tenant, Role::Agent);
        $this->agent->forceFill(['whatsapp' => '6281211112222'])->save();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    /** A shop order form: name, WhatsApp, product, colour, quantity, address, a photo and a UTM field. */
    private function orderForm(array $attributes = []): LeadForm
    {
        return $this->inTenant(fn () => LeadForm::create($attributes + [
            'name' => 'Pesanan Kaos', 'slug' => 'pesan-kaos', 'status' => 'active',
            'design' => ['title' => 'Pesan kaos', 'color' => '#0044ff'],
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'produk', 'type' => 'select', 'label' => 'Produk', 'required' => true, 'options' => ['Kaos Polos', 'Kaos Sablon'], 'maps_to' => 'variant'],
                ['key' => 'warna', 'type' => 'radio', 'label' => 'Warna', 'options' => ['Hitam', 'Putih'], 'maps_to' => 'variant'],
                ['key' => 'jumlah', 'type' => 'number', 'label' => 'Jumlah', 'required' => true, 'maps_to' => 'quantity'],
                ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address'],
                ['key' => 'tanggal', 'type' => 'date', 'label' => 'Tanggal kirim'],
                ['key' => 'setuju', 'type' => 'checkbox', 'label' => 'Saya setuju dihubungi', 'required' => true],
                ['key' => 'bukti', 'type' => 'photo', 'label' => 'Foto desain'],
                ['key' => 'sumber', 'type' => 'hidden', 'label' => 'Sumber', 'source' => 'utm_source'],
            ]),
            'submit' => ['label' => 'Pesan sekarang'],
            'after_submit' => ['type' => 'message', 'message' => 'Pesanan diterima.'],
        ]));
    }

    private function answers(array $override = []): array
    {
        return ['f' => array_replace([
            'nama' => 'Rina', 'wa' => '0812 3456 7890', 'produk' => 'Kaos Sablon', 'warna' => 'Hitam', 'jumlah' => '3',
            'alamat' => ['street' => 'Jl. Mawar 5', 'rt_rw' => '001/002', 'landmark' => 'dekat masjid', 'kelurahan' => 'Sukamaju', 'kecamatan' => 'Tambun Selatan',
                'kota' => 'Kab. Bekasi', 'provinsi' => 'Jawa Barat', 'kode_pos' => '17510'],
            'tanggal' => '2026-11-20', 'setuju' => '1', 'sumber' => 'instagram',
        ], $override)];
    }

    private function tracking(array $attributes = []): TrackingSetting
    {
        return $this->inTenant(fn () => TrackingSetting::create($attributes + [
            'meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => 'CABC123XYZ', 'google_tag_id' => 'AW-123456789', 'google_ads_label' => 'abcDEF12',
            'credentials' => ['meta_capi_token' => 'meta-secret', 'tiktok_events_token' => 'tt-secret'],
        ]));
    }

    // ---- Form builder -------------------------------------------------------------------------------------------

    public function test_builder_creates_forms_from_templates_with_their_own_address(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(CreateLeadForm::class)->fillForm(['template' => 'pesanan', 'name' => 'Promo Kaos', 'slug' => 'promo-kaos'])
            ->call('create')->assertHasNoFormErrors();

        $form = LeadForm::query()->where('slug', 'promo-kaos')->firstOrFail();
        $this->assertSame('whatsapp', $form->after_submit['type']);
        $this->assertContains('address', array_column($form->fieldList(), 'type'));

        $this->get('/f/griya-prima/promo-kaos')->assertOk()
            ->assertSee('name="f[alamat][kecamatan]"', false)->assertSee('name="f[produk]"', false)->assertSee('enctype="multipart/form-data"', false);
        $this->get('/f/lain/promo-kaos')->assertNotFound();
    }

    public function test_builder_requires_a_name_field_and_a_way_to_contact(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $form = $this->orderForm();

        Livewire::test(EditLeadForm::class, ['record' => $form->getKey()])
            ->fillForm(['fields' => [['key' => 'catatan', 'type' => 'textarea', 'label' => 'Catatan', 'maps_to' => 'note']]])
            ->call('save')->assertHasFormErrors(['fields']);

        $this->assertSame(['Formulir perlu satu kolom yang disimpan ke "Nama lead".', 'Formulir perlu kolom yang disimpan ke "Telepon / WhatsApp lead" atau "Email lead".'],
            FormFields::problems([['key' => 'catatan', 'maps_to' => 'note']]));
        $this->assertSame([], FormFields::problems($form->fieldList()));
    }

    public function test_field_order_and_labels_from_the_builder_reach_the_page(): void
    {
        $form = $this->orderForm();
        $html = $this->get('/f/griya-prima/pesan-kaos')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'name="f[jumlah]"'), strpos($html, 'name="f[produk]"'));
        $this->assertStringContainsString('<option value="Kaos Sablon">', $html);
        $this->assertStringContainsString('type="radio" name="f[warna]" value="Hitam"', $html);
        $this->assertStringContainsString('type="hidden" name="f[sumber]" data-src="utm_source"', $html);
        $this->assertStringContainsString('type="date" name="f[tanggal]"', $html);
        $this->assertStringContainsString('type="file" name="f[bukti]"', $html);
        $this->assertStringContainsString('#0044ff', $html);
        $this->assertSame(1, $form->fresh()->views);
    }

    // ---- Submissions ----------------------------------------------------------------------------------------------

    public function test_required_fields_and_whatsapp_number_are_checked_on_the_server(): void
    {
        $this->orderForm();

        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers(['nama' => '', 'jumlah' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['f.nama', 'f.jumlah']);
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers(['wa' => '0212345678']))
            ->assertStatus(422)->assertJsonPath('errors', fn ($e) => str_contains($e['f.wa'][0], 'diawali 08 atau 62'));
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers(['produk' => 'Barang lain']))->assertStatus(422)->assertJsonValidationErrors(['f.produk']);
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers(['alamat' => ['street' => 'Jl. Mawar']]))
            ->assertStatus(422)->assertJsonValidationErrors(['f.alamat.kecamatan', 'f.alamat.kota']);
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers(['setuju' => null]))->assertStatus(422)->assertJsonValidationErrors(['f.setuju']);

        $this->assertSame(0, $this->inTenant(fn () => Lead::count()));
        $this->assertTrue(FormFields::validPhone('6281234567890'));
        $this->assertTrue(FormFields::validPhone('+62 812-3456-7890'));
        $this->assertFalse(FormFields::validPhone('81234567890'));
    }

    public function test_answers_are_mapped_onto_the_lead_and_kept_in_full(): void
    {
        $form = $this->orderForm();

        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers() + ['utm_campaign' => 'promo-nov'])
            ->assertOk()->assertJsonPath('action.type', 'message')->assertJsonPath('action.message', 'Pesanan diterima.');

        $lead = $this->inTenant(fn () => Lead::query()->with('source')->firstOrFail());
        $this->assertSame('Rina', $lead->name);
        $this->assertSame('+6281234567890', $lead->phone);
        $this->assertSame('Kaos Sablon; Hitam', $lead->variant);
        $this->assertSame(3, (int) $lead->quantity);
        $this->assertSame('Jl. Mawar 5, RT/RW 001/002, (dekat masjid), Kel. Sukamaju, Kec. Tambun Selatan, Kab. Bekasi, Jawa Barat 17510', $lead->shipping_address);
        $this->assertSame(['Tanggal kirim' => '20/11/2026', 'Saya setuju dihubungi' => 'Ya', 'Sumber' => 'instagram'], $lead->custom_fields['formulir']);
        $this->assertSame(['utm_campaign' => 'promo-nov'], $lead->custom_fields['kampanye']);
        $this->assertSame('Formulir: Pesanan Kaos', $lead->source->name);
        $this->assertStringContainsString('- Warna: Hitam', $this->inTenant(fn () => $lead->activities()->where('body', 'like', 'Jawaban formulir%')->value('body')));
        $this->assertSame(1, $form->fresh()->submissions);
    }

    public function test_photo_is_stored_privately_and_shown_only_to_people_who_see_the_lead(): void
    {
        Storage::fake('local');
        $this->orderForm();

        $this->post('/f/griya-prima/pesan-kaos', ['f' => $this->answers()['f'] + ['bukti' => UploadedFile::fake()->image('desain.jpg', 400, 300)]] + $this->answers(), ['Accept' => 'application/json'])->assertOk();

        $lead = $this->inTenant(fn () => Lead::firstOrFail());
        $photo = $lead->custom_fields['foto_formulir'][0];
        $this->assertSame('Foto desain', $photo['label']);
        $this->assertStringStartsWith("lead-uploads/{$this->tenant->getKey()}/{$lead->getKey()}/", $photo['path']);
        Storage::disk('local')->assertExists($photo['path']);

        $this->get(route('lead.photo', [$lead->getKey(), 0]))->assertNotFound();
        $this->actingAs($this->adminOf($this->tenant));
        $this->get(route('lead.photo', [$lead->getKey(), 0]))->assertOk();

        $stranger = $this->makeTenant('agensi-lain');
        $this->actingAs($this->adminOf($stranger));
        $this->get(route('lead.photo', [$lead->getKey(), 0]))->assertNotFound();
    }

    public function test_a_returning_lead_is_not_duplicated_but_gets_the_new_answers(): void
    {
        $this->orderForm();
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers())->assertOk();
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers(['produk' => 'Kaos Polos']))->assertOk();

        $this->assertSame(1, $this->inTenant(fn () => Lead::count()));
        $this->assertSame(2, $this->inTenant(fn () => Lead::firstOrFail()->activities()->where('body', 'like', 'Jawaban formulir%')->count()));
    }

    public function test_bots_and_inactive_or_foreign_forms_store_nothing(): void
    {
        $form = $this->orderForm();

        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers() + ['website' => 'spam.example'])->assertOk();
        $form->forceFill(['status' => 'inactive'])->save();
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers())->assertNotFound();
        $this->get('/f/griya-prima/pesan-kaos')->assertNotFound();
        $this->postJson('/f/agensi-lain/pesan-kaos', $this->answers())->assertNotFound();

        $this->assertSame(0, $this->inTenant(fn () => Lead::count()));
    }

    public function test_without_script_the_form_still_saves_and_thanks(): void
    {
        $this->orderForm();

        $this->post('/f/griya-prima/pesan-kaos', $this->answers())->assertOk()->assertSee('Pesanan diterima.');
        $this->post('/f/griya-prima/pesan-kaos', $this->answers(['nama' => '']))->assertStatus(422)->assertSee('Data belum lengkap');
        $this->assertSame(1, $this->inTenant(fn () => Lead::count()));
    }

    // ---- After sending ----------------------------------------------------------------------------------------------

    public function test_after_sending_can_open_a_thank_you_page(): void
    {
        $thanks = $this->inTenant(fn () => LandingPage::create(['title' => 'Terima kasih', 'slug' => 'terima-kasih', 'status' => 'published', 'blocks' => []]));
        $this->orderForm(['after_submit' => ['type' => 'redirect', 'page_kind' => 'landing', 'landing_page_id' => $thanks->getKey()]]);

        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers())->assertJsonPath('action.type', 'redirect')
            ->assertJsonPath('action.url', url('/p/griya-prima/terima-kasih'));
        $this->post('/f/griya-prima/pesan-kaos', $this->answers(['wa' => '081299990000']))->assertRedirect(url('/p/griya-prima/terima-kasih'));
        $this->assertSame(2, $this->inTenant(fn () => Lead::count()), 'the lead is saved before the visitor leaves');

        $this->inTenant(fn () => LeadForm::query()->update(['after_submit' => json_encode(['type' => 'redirect', 'page_kind' => 'url', 'url' => 'javascript:alert(1)'])]));
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers(['wa' => '081277776666']))->assertJsonPath('action.type', 'message');
    }

    public function test_after_sending_opens_whatsapp_of_the_assigned_agent_with_the_answers(): void
    {
        $this->inTenant(fn () => $this->tenant->users()->updateExistingPivot($this->adminOf($this->tenant)->getKey(), ['takes_leads' => false]));
        $this->orderForm(['after_submit' => ['type' => 'whatsapp', 'wa_target' => 'rotate',
            'wa_message' => "Halo, saya {nama} pesan {produk} warna {warna} x{jumlah}.\nAlamat: {alamat}"]]);

        $res = $this->postJson('/f/griya-prima/pesan-kaos', $this->answers())->assertJsonPath('action.type', 'whatsapp');

        $lead = $this->inTenant(fn () => Lead::firstOrFail());
        $this->assertSame($this->agent->getKey(), $lead->owner_id);
        $url = $res->json('action.url');
        $this->assertStringStartsWith('https://wa.me/6281211112222?text=', $url);
        $this->assertSame("Halo, saya Rina pesan Kaos Sablon warna Hitam x3.\nAlamat: ".$lead->shipping_address, rawurldecode(substr($url, strpos($url, '=') + 1)));
    }

    public function test_after_sending_to_a_typed_number(): void
    {
        $this->orderForm(['after_submit' => ['type' => 'whatsapp', 'wa_target' => 'manual', 'wa_number' => '0813 0000 1111', 'wa_message' => 'Pesanan {nama}: {tidak_ada}{produk}']]);

        $url = $this->postJson('/f/griya-prima/pesan-kaos', $this->answers())->json('action.url');
        $this->assertSame('https://wa.me/6281300001111?text='.rawurlencode('Pesanan Rina: Kaos Sablon'), $url);
    }

    // ---- Older single form ------------------------------------------------------------------------------------------

    public function test_the_older_form_becomes_the_main_form_without_changing_its_link(): void
    {
        $token = $this->tenant->regenerateCaptureToken();
        $this->tenant->forceFill(['capture_settings' => ['title' => 'Daftar Open House', 'button' => 'Ikut', 'ask_email' => true, 'ask_note' => false, 'success' => 'Sip!']])->save();

        $attributes = LeadForm::legacyAttributes($this->tenant->capture_settings);
        $this->assertSame(['nama', 'whatsapp', 'email', 'utm_source'], array_column($attributes['fields'], 'key'));

        $this->get("/f/{$token}")->assertOk()->assertSee('Daftar Open House')->assertSee('Ikut')->assertSee('name="f[email]"', false)->assertDontSee('name="f[pesan]"', false);
        $this->assertSame(1, $this->inTenant(fn () => LeadForm::query()->where('is_primary', true)->count()));

        $this->postJson('/f/griya-prima/utama', ['f' => ['nama' => 'Budi', 'whatsapp' => '081211110000']])->assertJsonPath('action.message', 'Sip!');
        $this->assertSame('Formulir web', $this->inTenant(fn () => Lead::firstOrFail()->source->name));

        // The capture endpoint for agencies' own HTML forms keeps working too.
        $this->postJson("/capture/{$token}", ['name' => 'Ani', 'phone' => '081233334444'])->assertOk();
    }

    public function test_migration_turns_existing_form_settings_into_a_main_form(): void
    {
        $this->tenant->regenerateCaptureToken();
        $this->tenant->forceFill(['capture_settings' => ['title' => 'Info Cluster', 'color' => '#123456']])->save();
        $migration = require database_path('migrations/2026_11_07_000100_create_lead_forms.php');
        // Later migrations hang off lead_forms; they are taken off and put back around it.
        $welcome = require database_path('migrations/2026_11_18_000100_create_welcome_messages.php');
        $welcome->down();
        $migration->down();
        $migration->up();
        $welcome->up();

        $form = $this->inTenant(fn () => LeadForm::query()->where('is_primary', true)->firstOrFail());
        $this->assertSame('Info Cluster', $form->design['title']);
        $this->assertSame('#123456', $form->color());
        $this->assertSame('utama', $form->slug);
    }

    // ---- Buttons ----------------------------------------------------------------------------------------------------

    private function buttonsPage(array $buttons, array $attributes = []): LandingPage
    {
        return $this->inTenant(fn () => LandingPage::create($attributes + [
            'title' => 'Promo Kaos', 'slug' => 'promo', 'status' => 'published', 'whatsapp_number' => '081255556666',
            'blocks' => [['type' => 'buttons', 'data' => ['heading' => 'Pilih cara pesan', 'buttons' => $buttons]]],
        ]));
    }

    public function test_buttons_open_whatsapp_pages_the_form_or_a_call(): void
    {
        $thanks = $this->inTenant(fn () => LandingPage::create(['title' => 'Katalog', 'slug' => 'katalog', 'status' => 'published', 'blocks' => []]));
        $this->buttonsPage([
            ['label' => 'WA halaman', 'action' => 'whatsapp', 'wa_target' => 'agency', 'wa_message' => 'Halo, saya tertarik {judul}'],
            ['label' => 'WA admin', 'action' => 'whatsapp', 'wa_target' => 'manual', 'wa_number' => '0813 1111 2222'],
            ['label' => 'WA giliran', 'action' => 'whatsapp', 'wa_target' => 'rotate', 'wa_message' => 'Mau pesan'],
            ['label' => 'Katalog', 'action' => 'page', 'page_kind' => 'landing', 'landing_page_id' => $thanks->getKey(), 'new_tab' => true],
            ['label' => 'Situs', 'action' => 'page', 'page_kind' => 'url', 'url' => 'https://contoh.id/promo'],
            ['label' => 'Jahat', 'action' => 'page', 'page_kind' => 'url', 'url' => 'javascript:alert(1)'],
            ['label' => 'Isi formulir', 'action' => 'scroll', 'color' => 'outline', 'size' => 'sm', 'icon' => 'down'],
            ['label' => 'Telepon', 'action' => 'tel', 'tel' => '0812 9999 8888', 'icon' => 'phone'],
        ]);

        $html = $this->get('/p/griya-prima/promo')->assertOk()->getContent();

        $this->assertStringContainsString('href="https://wa.me/6281255556666?text='.rawurlencode('Halo, saya tertarik Promo Kaos').'"', $html);
        $this->assertStringContainsString('href="https://wa.me/6281311112222?text="', $html);
        $this->assertStringContainsString('href="'.url('/w/griya-prima').'?text=Mau%20pesan"', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(url('/p/griya-prima/katalog'), '#').'" data-action="page"\s+target="_blank" rel="noopener"#', $html);
        $this->assertStringContainsString('href="https://contoh.id/promo"', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('>Jahat<', $html);
        $this->assertStringContainsString('class="jb jb-outline jb-sm " href="#daftar" data-action="scroll"', $html);
        $this->assertStringContainsString('href="tel:+6281299998888"', $html);
        $this->assertStringContainsString('id="daftar"', $html, 'pages without a form block still get the main form to scroll to');
    }

    public function test_round_robin_whatsapp_goes_to_each_agent_in_turn(): void
    {
        $second = $this->member($this->tenant, Role::Agent);
        $second->forceFill(['whatsapp' => '6281233334444'])->save();

        $first = $this->get('/w/griya-prima?text=Halo')->assertRedirect()->headers->get('Location');
        $next = $this->get('/w/griya-prima?text=Halo')->headers->get('Location');
        $third = $this->get('/w/griya-prima?text=Halo')->headers->get('Location');

        $this->assertNotSame($first, $next);
        $this->assertSame($first, $third);
        $this->assertEqualsCanonicalizing(['https://wa.me/6281211112222?text=Halo', 'https://wa.me/6281233334444?text=Halo'], [$first, $next]);
        $this->get('/w/tidak-ada')->assertNotFound();
    }

    public function test_hero_and_cta_saved_before_buttons_keep_their_button(): void
    {
        $this->inTenant(fn () => LandingPage::create([
            'title' => 'Lama', 'slug' => 'lama', 'status' => 'published', 'whatsapp_number' => '081255556666',
            'blocks' => [
                ['type' => 'hero', 'data' => ['headline' => 'Judul', 'cta_label' => 'Daftar dulu']],
                ['type' => 'cta', 'data' => ['heading' => 'Tanya', 'label' => 'Chat kami', 'message' => 'Halo kak', 'number' => '0813 1111 2222']],
            ],
        ]));

        $this->get('/p/griya-prima/lama')->assertOk()
            ->assertSee('href="#daftar" data-action="scroll"', false)->assertSee('Daftar dulu')
            ->assertSee('href="https://wa.me/6281311112222?text=Halo%20kak"', false)->assertSee('Chat kami');
    }

    // ---- Pixels -----------------------------------------------------------------------------------------------------

    public function test_every_standard_event_of_each_platform_is_offered_with_a_description(): void
    {
        $meta = ['AddPaymentInfo', 'AddToCart', 'AddToWishlist', 'CompleteRegistration', 'Contact', 'CustomizeProduct', 'Donate', 'FindLocation',
            'InitiateCheckout', 'Lead', 'Purchase', 'Schedule', 'Search', 'StartTrial', 'SubmitApplication', 'Subscribe', 'ViewContent'];
        $tiktok = ['ViewContent', 'ClickButton', 'Search', 'AddToWishlist', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'CompletePayment',
            'PlaceAnOrder', 'Contact', 'Download', 'SubmitForm', 'CompleteRegistration', 'Subscribe', 'Lead', 'Purchase', 'Schedule'];
        $google = ['generate_lead', 'sign_up', 'login', 'contact', 'purchase', 'begin_checkout', 'add_to_cart', 'add_payment_info', 'add_shipping_info',
            'view_item', 'view_item_list', 'select_item', 'select_content', 'add_to_wishlist', 'search', 'share', 'view_promotion', 'select_promotion'];

        $this->assertEqualsCanonicalizing($meta, array_keys(PixelEvents::META));
        $this->assertEmpty(array_diff($tiktok, array_keys(PixelEvents::TIKTOK)));
        $this->assertEqualsCanonicalizing($google, array_keys(PixelEvents::GOOGLE));
        $this->assertSame(['Lead', 'SubmitForm', 'generate_lead'], [array_key_first(TrackingSetting::EVENTS['meta']), array_key_first(TrackingSetting::EVENTS['tiktok']), array_key_first(TrackingSetting::EVENTS['google'])]);
        $this->assertSame('Contact (menghubungi (WhatsApp, telepon, chat))', PixelEvents::options('meta')['Contact']);
    }

    public function test_each_button_fires_its_own_events_or_the_default_ones(): void
    {
        $this->tracking(['button_events' => ['meta' => 'Contact', 'tiktok' => 'none', 'google' => 'contact']]);
        $this->buttonsPage([
            ['label' => 'Bawaan', 'action' => 'whatsapp', 'wa_target' => 'agency'],
            ['label' => 'Beli', 'action' => 'whatsapp', 'wa_target' => 'agency', 'pixel' => [
                'meta' => 'InitiateCheckout', 'tiktok' => 'PlaceAnOrder', 'google' => 'begin_checkout', 'send_to' => 'xyz123', 'value' => '150000', 'content_name' => 'Kaos Sablon']],
            ['label' => 'Kustom', 'action' => 'scroll', 'pixel' => ['meta' => 'custom', 'meta_custom' => 'KlikPromo', 'tiktok' => 'none', 'google' => 'none']],
            ['label' => 'Tanpa', 'action' => 'scroll', 'pixel' => ['meta' => 'none', 'tiktok' => 'none', 'google' => 'none']],
        ]);

        $html = $this->get('/p/griya-prima/promo')->assertOk()->getContent();
        $px = fn (string $label) => preg_match('#<a [^>]*data-px="([^"]*)"[^>]*>(?:<svg.*?</svg>)?<span>'.$label.'</span>#s', $html, $m) ? json_decode(html_entity_decode($m[1]), true) : null;

        $this->assertSame(['meta' => ['Contact', false], 'google' => 'contact'], $px('Bawaan'));
        $this->assertSame(['meta' => ['InitiateCheckout', false], 'tiktok' => 'PlaceAnOrder', 'google' => 'begin_checkout', 'send_to' => 'AW-123456789/xyz123',
            'value' => 150000, 'currency' => 'IDR', 'content_name' => 'Kaos Sablon'], $px('Beli'));
        $this->assertSame(['meta' => ['KlikPromo', true]], $px('Kustom'));
        $this->assertNull($px('Tanpa'));
        $this->assertStringContainsString('window.jlPx', $html);
        $this->assertStringContainsString('setTimeout(function () { location.href = a.href; }, 300)', $html, 'clicks wait for the pixels before leaving');
    }

    public function test_pixels_render_only_for_ids_that_are_filled_in_and_on_plans_that_include_them(): void
    {
        $this->tracking(['tiktok_pixel_id' => null, 'google_tag_id' => null]);
        $button = ['label' => 'Beli', 'action' => 'scroll', 'pixel' => ['meta' => 'Purchase', 'tiktok' => 'Purchase', 'google' => 'purchase']];
        $this->buttonsPage([$button]);

        $html = $this->get('/p/griya-prima/promo')->getContent();
        $this->assertStringContainsString('data-px="{&quot;meta&quot;:[&quot;Purchase&quot;,false]}"', $html);
        $this->assertStringNotContainsString('analytics.tiktok.com', $html);

        Artisan::call('plan:set', ['tenant' => $this->tenant->slug, 'plan' => 'mandiri']);
        $this->tenant->unsetRelation('subscription');
        $html = $this->get('/p/griya-prima/promo')->getContent();
        $this->assertStringNotContainsString('data-px', $html);
        $this->assertStringNotContainsString('fbevents.js', $html);
        $this->assertStringNotContainsString('window.jlPx = function', $html);
    }

    public function test_thank_you_page_fires_its_event_when_opened(): void
    {
        $this->tracking();
        $this->inTenant(fn () => LandingPage::create(['title' => 'Terima kasih', 'slug' => 'terima-kasih', 'status' => 'published', 'blocks' => [],
            'page_events' => ['meta' => 'CompleteRegistration', 'tiktok' => 'CompleteRegistration', 'google' => 'none', 'value' => 0]]));
        $this->orderForm(['page_events' => ['meta' => 'ViewContent', 'tiktok' => 'none', 'google' => 'view_item', 'content_name' => '</script><b>x']]);

        $page = $this->get('/p/griya-prima/terima-kasih')->getContent();
        $this->assertStringContainsString('jlPx(JSON.parse("{\\u0022meta\\u0022:[\\u0022CompleteRegistration\\u0022,false],\\u0022tiktok\\u0022:\\u0022CompleteRegistration\\u0022,\\u0022value\\u0022:0,\\u0022currency\\u0022:\\u0022IDR\\u0022}"))', $page);

        $form = $this->get('/f/griya-prima/pesan-kaos')->getContent();
        $this->assertStringContainsString('\\u0022google\\u0022:\\u0022view_item\\u0022', $form);
        $this->assertStringNotContainsString('</script><b>x', $form);

        $this->get('/p/griya-prima/biasa')->assertNotFound();
        $this->assertNull(PixelEvents::resolve(null, [], TrackingSetting::query()->withoutGlobalScopes()->first()), 'pages fire nothing unless set');
    }

    public function test_form_submit_uses_the_same_event_id_in_browser_and_server_events(): void
    {
        Queue::fake();
        $this->tracking();
        $this->orderForm(['submit' => ['label' => 'Pesan', 'pixel' => ['meta' => 'Purchase', 'tiktok' => 'none', 'google' => 'default', 'value' => '99000', 'content_name' => 'Kaos']]]);

        $html = $this->get('/f/griya-prima/pesan-kaos')->getContent();
        $this->assertStringContainsString('data-px-submit="{&quot;meta&quot;:[&quot;Purchase&quot;,false],&quot;google&quot;:&quot;generate_lead&quot;,&quot;send_to&quot;:&quot;AW-123456789/abcDEF12&quot;,&quot;value&quot;:99000,&quot;currency&quot;:&quot;IDR&quot;,&quot;content_name&quot;:&quot;Kaos&quot;}"', $html);
        $this->assertStringContainsString('jlPx(JSON.parse(form.dataset.pxSubmit), eventId)', $html);
        $this->assertStringContainsString("fd.set('event_id', eventId)", $html);

        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers() + ['event_id' => 'evt-123', 'fbp' => 'fb.1.1.2'])->assertOk();

        $sent = null;
        Queue::assertPushed(SendConversionEvents::class, function (SendConversionEvents $job) use (&$sent) {
            $sent = ConversionPayload::fromArray($job->payload);

            return true;
        });
        $this->assertSame('evt-123', $sent->eventId);
        $this->assertSame(['value' => 99000.0, 'currency' => 'IDR', 'content_name' => 'Kaos', 'meta' => 'Purchase', 'tiktok' => null], $sent->event);
        $this->assertSame(hash('sha256', '6281234567890'), $sent->phoneHash);

        // A returning visitor is not reported again.
        $this->postJson('/f/griya-prima/pesan-kaos', $this->answers() + ['event_id' => 'evt-456'])->assertOk();
        Queue::assertPushed(SendConversionEvents::class, 1);

        Http::fake();
        $setting = $this->inTenant(fn () => TrackingSetting::firstOrFail());
        app(MetaConversions::class)->send($setting, $sent);
        app(TikTokEvents::class)->send($setting, $sent);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && $r['data'][0]['event_name'] === 'Purchase'
            && $r['data'][0]['event_id'] === 'evt-123' && $r['data'][0]['custom_data'] == ['value' => 99000, 'currency' => 'IDR', 'content_name' => 'Kaos']);
        Http::assertSentCount(1);
    }

    public function test_button_link_helper_refuses_unsafe_or_incomplete_buttons(): void
    {
        $ctx = ['tenant' => $this->tenant, 'wa' => null, 'vars' => [], 'anchor' => '#daftar'];

        $this->assertNull(Buttons::link(['action' => 'whatsapp', 'wa_target' => 'agency'], $ctx), 'no agency number');
        $this->assertNull(Buttons::link(['action' => 'page', 'page_kind' => 'url', 'url' => 'data:text/html,x'], $ctx));
        $this->assertNull(Buttons::link(['action' => 'tel', 'tel' => 'abc'], $ctx));
        $this->assertSame('Halo Budi', Buttons::fill('Halo {nama}{lain}', ['nama' => 'Budi']));
    }
}
