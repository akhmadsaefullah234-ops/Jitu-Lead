<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\LeadCapture;
use App\Filament\Pages\TrackingSettings;
use App\Filament\Resources\LandingPages\LandingPageResource;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPages\Pages\ListLandingPages;
use App\Jobs\SendConversionEvents;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Tracking\ConversionPayload;
use App\Tracking\MetaConversions;
use App\Tracking\TikTokEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class WebBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->token = $this->tenant->regenerateCaptureToken();
        $this->member($this->tenant, Role::Agent);
    }

    private function inTenant(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->tenant, $fn);
    }

    private function page(array $attributes = [], ?Tenant $tenant = null): LandingPage
    {
        return $this->inTenant(fn () => LandingPage::create($attributes + [
            'title' => 'Cluster Bukit Asri', 'slug' => 'bukit-asri', 'status' => 'published', 'color' => '#dc2626',
            'blocks' => [
                ['type' => 'hero', 'data' => ['headline' => 'Rumah impian keluarga', 'subheadline' => 'Cicilan ringan']],
                ['type' => 'form', 'data' => ['heading' => 'Daftar sekarang', 'button' => 'Kirim data']],
            ],
        ]), $tenant);
    }

    private function tracking(array $credentials = []): TrackingSetting
    {
        return $this->inTenant(fn () => TrackingSetting::create([
            'meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => 'CABC123XYZ', 'google_tag_id' => 'AW-123456789', 'google_ads_label' => 'abcDEF12',
            'credentials' => $credentials + ['meta_capi_token' => 'meta-secret', 'tiktok_events_token' => 'tt-secret'],
        ]));
    }

    public function test_hosted_form_page_renders_real_form_with_agency_settings(): void
    {
        $this->tenant->forceFill(['capture_settings' => ['title' => 'Daftar Open House', 'button' => 'Ikut sekarang', 'color' => '#0044ff']])->save();

        $this->get("/f/{$this->token}")->assertOk()->assertSee('Daftar Open House')->assertSee('Ikut sekarang')->assertSee('#0044ff')
            ->assertSee('name="phone"', false)->assertSee('name="website"', false)->assertSee('noindex', false);

        $this->get('/f/salah')->assertNotFound();
        $this->tenant->forceFill(['status' => 'suspended'])->save();
        $this->get("/f/{$this->token}")->assertNotFound();
    }

    public function test_published_page_is_public_and_escapes_everything_users_typed(): void
    {
        $this->page(['blocks' => [
            ['type' => 'hero', 'data' => ['headline' => '<script>alert(1)</script>Judul']],
            ['type' => 'text', 'data' => ['heading' => 'Tentang', 'body' => "Baris <b>satu</b>\n\nParagraf dua"]],
            ['type' => 'location', 'data' => ['heading' => 'Lokasi', 'map_url' => 'https://evil.example/steal']],
            ['type' => 'unknown_block', 'data' => ['x' => 'y']],
        ]]);

        $html = $this->get('/p/griya-prima/bukit-asri')->assertOk()->assertSee('Judul')->assertSee('Paragraf dua')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<b>satu</b>', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('unknown_block', $html);
        $this->assertStringContainsString('id="daftar"', $html); // a form is added when the page has none
    }

    public function test_draft_is_hidden_but_signed_preview_shows_it_without_pixels_or_indexing(): void
    {
        $this->tracking();
        $page = $this->page(['status' => 'draft']);

        $this->get('/p/griya-prima/bukit-asri')->assertNotFound();
        $this->get('/p/griya-prima/bukit-asri/pratinjau')->assertForbidden();

        $signed = LandingPageResource::previewUrl($page);
        $this->get($signed)->assertOk()->assertSee('noindex', false)->assertDontSee('fbevents.js', false);
    }

    public function test_pages_are_isolated_per_agency(): void
    {
        $other = $this->makeTenant('rumah-lain');
        $this->page(['title' => 'Milik Lain', 'slug' => 'rahasia'], $other);

        $this->get('/p/griya-prima/rahasia')->assertNotFound();
        $this->get('/p/rumah-lain/rahasia')->assertOk()->assertSee('Rumah Lain');
        $this->assertSame(0, $this->inTenant(fn () => LandingPage::count()));
    }

    public function test_view_counter_counts_public_visits_only(): void
    {
        $page = $this->page();
        $this->get('/p/griya-prima/bukit-asri');
        $this->get('/p/griya-prima/bukit-asri');
        $this->get(LandingPageResource::previewUrl($page));

        $this->assertSame(2, $page->fresh()->views);
    }

    public function test_pixels_are_installed_only_with_valid_ids_on_published_pages(): void
    {
        $this->page();
        $this->get('/p/griya-prima/bukit-asri')->assertDontSee('fbevents.js', false)->assertDontSee('googletagmanager', false);

        $this->tracking();
        $this->get('/p/griya-prima/bukit-asri')->assertSee('fbevents.js', false)->assertSee('1234567890', false)
            ->assertSee('analytics.tiktok.com', false)->assertSee('CABC123XYZ', false)
            ->assertSee('googletagmanager.com/gtag/js?id=AW-123456789', false)->assertSee('AW-123456789/abcDEF12', false)
            ->assertDontSee('meta-secret')->assertDontSee('tt-secret');
    }

    public function test_landing_submission_records_source_property_and_campaign(): void
    {
        $property = $this->inTenant(fn () => Property::create(['kind' => 'primary', 'name' => 'Bukit Asri', 'property_type' => 'Rumah']));
        $page = $this->page(['property_id' => $property->getKey()]);

        $this->postJson("/capture/{$this->token}", [
            'name' => 'Sinta', 'phone' => '0812 7777 8888', 'page' => $page->getKey(), 'utm_source' => 'facebook', 'utm_campaign' => 'oktober', 'event_id' => 'evt-1',
        ])->assertOk();

        $lead = $this->inTenant(fn () => Lead::firstOrFail());
        $this->assertSame('Landing: Cluster Bukit Asri', $this->inTenant(fn () => $lead->source->name));
        $this->assertSame($property->getKey(), $lead->property_id);
        $this->assertSame(['kampanye' => ['utm_source' => 'facebook', 'utm_campaign' => 'oktober']], $lead->custom_fields);
    }

    public function test_page_of_another_agency_is_ignored_on_submission(): void
    {
        $other = $this->makeTenant('rumah-lain');
        $foreign = $this->page(['title' => 'Asing', 'slug' => 'asing'], $other);

        $this->postJson("/capture/{$this->token}", ['name' => 'Sinta', 'phone' => '081277778888', 'page' => $foreign->getKey()])->assertOk();

        $lead = $this->inTenant(fn () => Lead::firstOrFail());
        $this->assertSame('Formulir web', $this->inTenant(fn () => $lead->source->name));
        $this->assertNull($lead->property_id);
    }

    public function test_event_is_queued_hashed_and_only_for_new_leads_with_tracking(): void
    {
        Queue::fake();
        $body = ['name' => 'Sinta', 'phone' => '0812 7777 8888', 'email' => 'Sinta@Contoh.ID', 'event_id' => 'evt-1', 'source_url' => 'https://app.test/p/griya-prima/bukit-asri'];

        $this->postJson("/capture/{$this->token}", $body)->assertOk();
        Queue::assertNothingPushed(); // no tracking configured yet

        $this->tracking();
        $this->postJson("/capture/{$this->token}", $body)->assertOk();
        Queue::assertNothingPushed(); // same person again: not a new lead

        $this->postJson("/capture/{$this->token}", ['name' => 'Budi', 'phone' => '081200001111', 'event_id' => 'evt-2', 'fbp' => 'fb.1.123.456', 'ttclid' => 'tt-abc'])->assertOk();
        Queue::assertPushed(SendConversionEvents::class, function (SendConversionEvents $job) {
            $json = json_encode($job->payload);

            return $job->payload['eventId'] === 'evt-2'
                && $job->payload['phoneHash'] === hash('sha256', '6281200001111')
                && $job->payload['ids'] === ['fbp' => 'fb.1.123.456', 'ttclid' => 'tt-abc']
                && ! str_contains($json, '081200001111') && ! str_contains($json, '+62812');
        });

        $this->postJson("/capture/{$this->token}", ['name' => 'Tanpa Id', 'phone' => '081200002222'])->assertOk();
        Queue::assertPushed(SendConversionEvents::class, 1); // no event_id from the browser: nothing to deduplicate against
    }

    public function test_job_sends_meta_and_tiktok_events_with_hashed_identifiers(): void
    {
        $this->tracking(['meta_test_event_code' => 'TEST123']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $payload = ConversionPayload::make('evt-9', 'Sinta@Contoh.ID ', '0812 7777 8888', 'https://app.test/p/x', '203.0.113.9', 'Mozilla/5.0', ['fbp' => 'fb.1.1.2', 'ttp' => 'tt-p']);
        (new SendConversionEvents($this->tenant->getKey(), $payload->toArray()))->handle(app(CurrentTenant::class), app(MetaConversions::class), app(TikTokEvents::class));

        Http::assertSentCount(2);
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'graph.facebook.com') || ! str_contains($r->url(), '/1234567890/events')) {
                return false;
            }
            $event = $r['data'][0];

            return $r->hasHeader('Authorization', 'Bearer meta-secret') && $r['test_event_code'] === 'TEST123'
                && $event['event_name'] === 'Lead' && $event['event_id'] === 'evt-9' && $event['action_source'] === 'website'
                && $event['user_data']['em'] === [hash('sha256', 'sinta@contoh.id')] && $event['user_data']['ph'] === [hash('sha256', '6281277778888')]
                && $event['user_data']['fbp'] === 'fb.1.1.2' && $event['user_data']['client_ip_address'] === '203.0.113.9';
        });
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'business-api.tiktok.com')) {
                return false;
            }

            return $r->hasHeader('Access-Token', 'tt-secret') && $r['event_source_id'] === 'CABC123XYZ'
                && $r['data'][0]['event'] === 'SubmitForm' && $r['data'][0]['event_id'] === 'evt-9' && $r['data'][0]['user']['ttp'] === 'tt-p';
        });
    }

    public function test_job_skips_platforms_without_tokens_and_one_failure_does_not_block_the_other(): void
    {
        $this->inTenant(fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => 'CABC123XYZ', 'credentials' => ['tiktok_events_token' => 'tt']]));
        Http::fake(['business-api.tiktok.com/*' => Http::response(['ok' => true])]);

        $payload = ConversionPayload::make('e', null, '081200001111', null, null, null)->toArray();
        (new SendConversionEvents($this->tenant->getKey(), $payload))->handle(app(CurrentTenant::class), app(MetaConversions::class), app(TikTokEvents::class));
        Http::assertSentCount(1); // Meta has no token, so only TikTok

        $this->inTenant(fn () => TrackingSetting::query()->update(['credentials' => app('encrypter')->encrypt(json_encode(['meta_capi_token' => 'm', 'tiktok_events_token' => 'tt']), false)]));
        Http::fake(['graph.facebook.com/*' => Http::response('boom', 500), 'business-api.tiktok.com/*' => Http::response(['ok' => true])]);

        try {
            (new SendConversionEvents($this->tenant->getKey(), $payload))->handle(app(CurrentTenant::class), app(MetaConversions::class), app(TikTokEvents::class));
            $this->fail('A failed platform should make the job retry.');
        } catch (RequestException) {
            Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tiktok'));
        }
    }

    public function test_chosen_form_event_is_used_in_browser_form_and_server_events(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(TrackingSettings::class)->callAction('edit', ['meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => 'CABC123XYZ', 'google_tag_id' => 'G-ABCD1234',
            'meta_event' => 'CompleteRegistration', 'tiktok_event' => 'Contact', 'google_event' => 'sign_up',
            'cred' => ['meta_capi_token' => 'm', 'tiktok_events_token' => 't']])->assertHasNoActionErrors();

        $setting = $this->inTenant(fn () => TrackingSetting::firstOrFail());
        $this->assertSame(['CompleteRegistration', 'Contact', 'sign_up'], [$setting->eventFor('meta'), $setting->eventFor('tiktok'), $setting->eventFor('google')]);

        Http::fake();
        $payload = ConversionPayload::make('e1', null, '081200001111', null, null, null);
        app(MetaConversions::class)->send($setting, $payload);
        app(TikTokEvents::class)->send($setting, $payload);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && $r['data'][0]['event_name'] === 'CompleteRegistration');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tiktok') && $r['data'][0]['event'] === 'Contact');

        $html = view('public.partials.lead-form', ['settings' => ['ask_email' => false, 'ask_note' => false, 'button' => 'Kirim', 'privacy' => '', 'show_privacy' => false, 'success' => 'Terima kasih'], 'endpoint' => '/x', 'tracking' => $setting, 'pageId' => null, 'formId' => 'f'])->render();
        $this->assertStringContainsString('data-meta-event="CompleteRegistration"', $html);
        $this->assertStringContainsString('data-google-event="sign_up"', $html);
    }

    public function test_unknown_form_event_is_rejected_and_unset_one_defaults(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(TrackingSettings::class)->callAction('edit', ['meta_pixel_id' => '1234567890', 'meta_event' => 'Purchase'])->assertHasActionErrors(['meta_event']);

        $setting = new TrackingSetting(['meta_event' => 'Bogus']);
        $this->assertSame('Lead', $setting->eventFor('meta'));
        $this->assertSame('SubmitForm', $setting->eventFor('tiktok'));
    }

    public function test_only_admin_edits_tracking_and_secrets_are_kept_hidden(): void
    {
        $this->actingInTenant($this->member($this->tenant, Role::Manager), $this->tenant);
        Livewire::test(TrackingSettings::class)->assertForbidden();

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(TrackingSettings::class)->callAction('edit', ['meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => 'CABC123XYZ', 'google_tag_id' => 'G-ABCD1234',
            'cred' => ['meta_capi_token' => 'rahasia-meta', 'tiktok_events_token' => 'rahasia-tt']])->assertHasNoActionErrors();

        $setting = $this->inTenant(fn () => TrackingSetting::firstOrFail());
        $this->assertSame('rahasia-meta', $setting->credential('meta_capi_token'));
        $this->assertStringNotContainsString('rahasia-meta', (string) $setting->getRawOriginal('credentials'));

        Livewire::test(TrackingSettings::class)->mountAction('edit')->assertSet('mountedActions.0.data.cred.meta_capi_token', null)->assertDontSee('rahasia-meta');

        Livewire::test(TrackingSettings::class)->callAction('edit', ['meta_pixel_id' => '1234567890', 'cred' => ['meta_capi_token' => '']])->assertHasNoActionErrors();
        $this->assertSame('rahasia-meta', $this->inTenant(fn () => TrackingSetting::firstOrFail())->credential('meta_capi_token'));
    }

    public function test_pixel_ids_that_could_inject_script_are_rejected(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(TrackingSettings::class)->callAction('edit', ['meta_pixel_id' => "1');alert(1);//"])->assertHasActionErrors(['meta_pixel_id']);
        Livewire::test(TrackingSettings::class)->callAction('edit', ['google_tag_id' => 'x"></script><script>'])->assertHasActionErrors(['google_tag_id']);
        Livewire::test(TrackingSettings::class)->callAction('edit', ['tiktok_pixel_id' => '<img src=x>'])->assertHasActionErrors(['tiktok_pixel_id']);
        $this->assertSame(0, $this->inTenant(fn () => TrackingSetting::count()));
    }

    public function test_builders_create_pages_from_starter_and_agents_cannot(): void
    {
        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        Livewire::test(ListLandingPages::class)->assertForbidden();

        $this->tenant->forceFill(['capture_token' => null])->save();
        $this->actingInTenant($this->member($this->tenant, Role::Manager), $this->tenant);
        Livewire::test(CreateLandingPage::class)->fillForm(['title' => 'Promo Oktober', 'slug' => 'promo-oktober', 'template' => 'cluster'])
            ->call('create')->assertHasNoFormErrors();

        $page = $this->inTenant(fn () => LandingPage::where('slug', 'promo-oktober')->firstOrFail());
        $this->assertNotEmpty($page->blocks);
        $this->assertSame('draft', $page->status); // sample copy is never public until the agent publishes it
        $this->assertNull($page->published_at);
        $this->assertNotNull($this->tenant->fresh()->capture_token); // the form on the page needs somewhere to post
        $this->get('/p/griya-prima/promo-oktober')->assertNotFound();
        $this->get(LandingPageResource::previewUrl($page))->assertOk()->assertSee('Hunian eksklusif');
    }

    public function test_page_list_renders_with_status_and_links(): void
    {
        $page = $this->page();
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ListLandingPages::class)->assertCanSeeTableRecords([$page])->assertSee('Terbit')->assertSee('/p/griya-prima/bukit-asri');
    }

    public function test_slug_is_unique_within_an_agency_only(): void
    {
        $this->page();
        $other = $this->makeTenant('rumah-lain');
        $this->page(['slug' => 'bukit-asri'], $other); // another agency may reuse it

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(CreateLandingPage::class)->fillForm(['title' => 'Salinan', 'slug' => 'bukit-asri', 'color' => '#dc2626'])->call('create')->assertHasFormErrors(['slug']);
        Livewire::test(CreateLandingPage::class)->fillForm(['title' => 'Salinan', 'slug' => 'Bukit Asri!', 'color' => '#dc2626'])->call('create')->assertHasFormErrors(['slug']);
    }

    public function test_only_admin_deletes_pages(): void
    {
        $page = $this->page();
        $manager = $this->member($this->tenant, Role::Manager);
        $stranger = $this->adminOf($this->makeTenant('rumah-lain'));

        $this->actingInTenant($manager, $this->tenant);
        $this->assertTrue($manager->can('update', $page));
        $this->assertFalse($manager->can('delete', $page));

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $this->assertTrue($this->adminOf($this->tenant)->can('delete', $page));

        $this->actingInTenant($stranger, $this->tenant); // not a member here
        $this->assertFalse($stranger->can('update', $page));
    }

    public function test_form_page_shows_live_preview_and_design_changes_reach_visitors(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(LeadCapture::class)->assertSee("/f/{$this->token}", false)->assertSee('iframe', false)
            ->callAction('design', ['title' => 'Booking Unit Hari Ini', 'intro' => 'Isi data', 'button' => 'Booking', 'success' => 'Siap!', 'color' => '#112233', 'ask_email' => true, 'ask_note' => false, 'show_privacy' => false])
            ->assertHasNoActionErrors();

        $this->get("/f/{$this->token}")->assertSee('Booking Unit Hari Ini')->assertSee('name="email"', false)->assertDontSee('name="note"', false)->assertDontSee('Dengan mengirim');
        $this->postJson("/capture/{$this->token}", ['name' => 'Sinta', 'phone' => '081200003333'])->assertJson(['message' => 'Siap!']);
    }

    public function test_inactive_form_is_clearly_unavailable_and_old_token_stops_working(): void
    {
        $this->tenant->forceFill(['capture_token' => null])->save();
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(LeadCapture::class)->assertSee('Formulir belum aktif')->callAction('generate')->assertSee('/f/', false);

        $old = $this->tenant->fresh()->capture_token;
        Livewire::test(LeadCapture::class)->callAction('generate');
        $this->get("/f/{$old}")->assertNotFound();
        $this->assertTrue(URL::isValidUrl($this->tenant->fresh()->captureUrl()));
    }
}
