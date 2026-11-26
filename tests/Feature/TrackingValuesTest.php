<?php

namespace Tests\Feature;

use App\Jobs\SendConversionEvents;
use App\LeadForms\FormFields;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Tracking\ConversionPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ad tags and browser ids never stop an order (26-11-2026: Meta's fbclid grew past 181 characters, the form's
 * fbc = "fb.1.<ms>." + fbclid passed the old 200 limit, and 82 buyers got a 422 they could not fix).
 */
class TrackingValuesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        Queue::fake();
        $this->in(fn () => TrackingSetting::create([
            'meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => 'CABC123XYZ',
            'credentials' => ['meta_capi_token' => 'meta-secret', 'tiktok_events_token' => 'tt-secret'],
        ]));
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function form(array $design = []): LeadForm
    {
        return $this->in(fn () => LeadForm::create([
            'name' => 'Pesanan', 'slug' => 'pesan', 'status' => 'active', 'design' => ['title' => 'Pesan'] + $design,
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama penerima', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
                ['key' => 'sumber', 'type' => 'hidden', 'label' => 'Sumber', 'source' => 'fbclid'],
                ['key' => 'alamat', 'type' => 'address', 'label' => 'Alamat', 'required' => true, 'maps_to' => 'shipping_address'],
            ]),
            'submit' => ['label' => 'Pesan'], 'after_submit' => ['type' => 'message', 'message' => 'Terima kasih.'],
        ]));
    }

    private function answers(array $override = []): array
    {
        return ['f' => array_replace([
            'nama' => 'Rina', 'wa' => '081234567890', 'sumber' => 'meta',
            'alamat' => ['street' => 'Jl. Mawar 5', 'rt_rw' => '001/002', 'landmark' => 'dekat masjid', 'kelurahan' => 'Sukamaju', 'kecamatan' => 'Tambun Selatan',
                'kota' => 'Kab. Bekasi', 'provinsi' => 'Jawa Barat', 'kode_pos' => '17510'],
        ], $override)];
    }

    /** What the form script sends: "fb.1." + 13-digit milliseconds + "." + fbclid. */
    private function fbc(int $length): string
    {
        $prefix = 'fb.1.1764162000000.';

        return substr($prefix.str_repeat('IwZXh0bgNhZW0BMABhZGlkAasd', 100), 0, $length);
    }

    private function lead(): Lead
    {
        return $this->in(fn () => Lead::query()->latest('id')->firstOrFail());
    }

    private function sentPayload(): ConversionPayload
    {
        $sent = null;
        Queue::assertPushed(SendConversionEvents::class, function (SendConversionEvents $job) use (&$sent) {
            $sent = ConversionPayload::fromArray($job->payload);

            return true;
        });

        return $sent;
    }

    // ---- Built forms (/f/{agency}/{form}) --------------------------------------------------------------------------

    public function test_an_fbc_of_230_characters_is_kept_whole_on_the_lead_and_in_the_conversion(): void
    {
        $this->form();
        $fbc = $this->fbc(230);
        $this->assertSame(230, strlen($fbc));

        $this->postJson('/f/griya-prima/pesan', $this->answers() + ['event_id' => 'evt-1', 'fbc' => $fbc, 'fbp' => 'fb.1.1764162000000.123', 'ttclid' => str_repeat('t', 450)])->assertOk();

        $lead = $this->lead();
        $this->assertSame($fbc, $lead->custom_fields['pelacakan']['fbc']);
        $this->assertSame(str_repeat('t', 450), $lead->custom_fields['pelacakan']['ttclid']);
        $this->assertSame($fbc, $this->sentPayload()->ids['fbc']);
    }

    public function test_an_fbc_of_2000_characters_is_dropped_and_the_lead_is_still_saved(): void
    {
        $this->form();
        Log::spy();

        $this->postJson('/f/griya-prima/pesan', $this->answers() + [
            'event_id' => 'evt-2', 'fbc' => $this->fbc(2000), 'fbp' => 'fb.1.1764162000000.123', 'ttclid' => str_repeat('t', 501),
            'utm_campaign' => str_repeat('c', 201), 'utm_source' => ['array'], 'source_url' => 'bukan alamat web', 'utm_medium' => 'cpc',
        ])->assertOk();

        $lead = $this->lead();
        $this->assertSame('Rina', $lead->name);
        $this->assertSame(['fbp' => 'fb.1.1764162000000.123'], $lead->custom_fields['pelacakan'], 'fbc and ttclid dropped whole, never cut');
        $this->assertSame(['utm_medium' => 'cpc'], $lead->custom_fields['kampanye'] ?? $lead->meta['kampanye'] ?? null);
        $sent = $this->sentPayload();
        $this->assertArrayNotHasKey('fbc', $sent->ids);
        $this->assertArrayNotHasKey('ttclid', $sent->ids);
        $this->assertNull($sent->sourceUrl);

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Public form: tracking values dropped'
            && $context['fields'] === ['source_url', 'utm_source', 'utm_campaign', 'fbc', 'ttclid'] && $context['lengths']['fbc'] === 2000
            && ! str_contains(json_encode($context), 'IwZXh0'))->once();
    }

    public function test_a_hidden_field_that_breaks_its_limit_is_emptied_rather_than_failing_the_order(): void
    {
        $this->form();

        $this->postJson('/f/griya-prima/pesan', $this->answers(['sumber' => str_repeat('x', 301)]))->assertOk();

        $this->assertSame('Rina', $this->lead()->name);
    }

    public function test_a_two_step_form_drops_a_long_fbc_at_step_one_and_keeps_a_valid_one_at_step_two(): void
    {
        $this->form(['two_step' => true]);

        $this->postJson('/f/griya-prima/pesan', ['step' => '1', 'f' => ['nama' => 'Rina', 'wa' => '081234567890'], 'fbc' => $this->fbc(2000)])
            ->assertOk()->assertJson(['ok' => true, 'step' => 2]);
        $this->assertSame(1, $this->in(fn () => Lead::query()->count()));

        $fbc = $this->fbc(230);
        $this->postJson('/f/griya-prima/pesan', $this->answers() + ['event_id' => 'evt-3', 'fbc' => $fbc])->assertOk();

        $this->assertSame(1, $this->in(fn () => Lead::query()->count()), 'the same lead');
        $this->assertSame($fbc, $this->lead()->custom_fields['pelacakan']['fbc']);
        $this->assertSame($fbc, $this->sentPayload()->ids['fbc']);
    }

    public function test_form_errors_are_in_indonesian_and_the_422_is_logged_with_field_names_only(): void
    {
        $this->form();
        Log::spy();

        $this->postJson('/f/griya-prima/pesan', $this->answers(['nama' => '', 'wa' => '081234567890']))
            ->assertStatus(422)->assertJsonPath('errors', fn ($e) => $e['f.nama'] === ['Nama penerima wajib diisi.']);

        $this->post('/f/griya-prima/pesan', $this->answers(['nama' => '']))
            ->assertStatus(422)->assertSee('Nama penerima wajib diisi.')->assertDontSee('field is required');

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Public form submission rejected (422)'
            && $context['fields'] === ['f.nama'] && $context['form'] !== null && ! str_contains(json_encode($context), '081234567890'))->twice();
        $this->assertSame(0, $this->in(fn () => Lead::query()->count()));
    }

    // ---- The capture endpoint (agencies' own HTML forms) -----------------------------------------------------------

    public function test_capture_keeps_an_fbc_of_230_characters_whole(): void
    {
        $fbc = $this->fbc(230);

        $this->postJson("/capture/{$this->tenant->regenerateCaptureToken()}", ['name' => 'Ani', 'phone' => '081233334444', 'event_id' => 'evt-4', 'fbc' => $fbc])->assertOk();

        $this->assertSame($fbc, $this->lead()->custom_fields['pelacakan']['fbc']);
        $this->assertSame($fbc, $this->sentPayload()->ids['fbc']);
    }

    public function test_capture_drops_an_fbc_of_2000_characters_and_saves_the_lead(): void
    {
        $this->postJson("/capture/{$this->tenant->regenerateCaptureToken()}", [
            'name' => 'Ani', 'phone' => '081233334444', 'event_id' => 'evt-5', 'fbc' => $this->fbc(2000), 'fbp' => 'fb.1.1.2', 'source_url' => 'x',
        ])->assertOk();

        $lead = $this->lead();
        $this->assertSame('Ani', $lead->name);
        $this->assertSame(['fbp' => 'fb.1.1.2'], $lead->custom_fields['pelacakan']);
        $this->assertArrayNotHasKey('fbc', $this->sentPayload()->ids);
    }

    public function test_capture_errors_are_in_indonesian(): void
    {
        $this->postJson("/capture/{$this->tenant->regenerateCaptureToken()}", ['phone' => '081233334444'])
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'Nama wajib diisi.');
    }
}
