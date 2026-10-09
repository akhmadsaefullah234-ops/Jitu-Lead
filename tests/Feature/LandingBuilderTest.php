<?php

namespace Tests\Feature;

use App\Filament\Resources\LandingPages\LandingPageResource;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\Filament\Resources\LandingPages\Pages\ListLandingPages;
use App\LandingPages\BlockFormat;
use App\LandingPages\Embeds;
use App\LandingPages\ImageOptimizer;
use App\LandingPages\RichText;
use App\LandingPages\Sections;
use App\LandingPages\Templates;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LandingBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->token = $this->tenant->regenerateCaptureToken();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    private function admin(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
    }

    private function page(array $attributes = []): LandingPage
    {
        return $this->inTenant(fn () => LandingPage::create($attributes + [
            'title' => 'Cluster Bukit Asri', 'slug' => 'bukit-asri', 'status' => 'published', 'color' => '#dc2626', 'blocks' => [],
        ]));
    }

    private function setPlan(string $plan): void
    {
        Artisan::call('plan:set', ['tenant' => $this->tenant->slug, 'plan' => $plan]);
        $this->tenant->unsetRelation('subscription');
    }

    public function test_there_are_five_templates_plus_a_blank_page(): void
    {
        $keys = array_keys(Templates::options());

        $this->assertEqualsCanonicalizing(['kosong', 'kpr', 'cluster', 'villa', 'kavling', 'second'], $keys);

        foreach ($keys as $key) {
            $blocks = Templates::blocks($key);
            $types = array_column($blocks, 'type');
            $this->assertNotEmpty($blocks, $key);
            $this->assertContains('form', $types, "$key needs the lead form");
            $this->assertContains('hero', $types, $key);
            $this->assertSame(array_column($blocks, 'type'), array_filter($types, fn ($t) => Sections::has($t)), "$key uses only known sections");
        }
    }

    public function test_villa_template_makes_no_return_promise(): void
    {
        $json = mb_strtolower(json_encode(Templates::blocks('villa'), JSON_UNESCAPED_UNICODE));

        $this->assertDoesNotMatchRegularExpression('/\b\d+\s?%/', $json, 'no percentage figures');
        $this->assertDoesNotMatchRegularExpression('/\broi\b|balik modal|untung pasti|pasti untung|dijamin untung/', $json);
        $this->assertStringContainsString('tidak dapat dijamin', $json);
    }

    public function test_every_template_can_be_created_edited_and_rendered(): void
    {
        $this->setPlan('agensi'); // unlimited pages, so all six can be made in one go
        $this->admin();

        foreach (array_keys(Templates::options()) as $key) {
            Livewire::test(CreateLandingPage::class)->fillForm(['template' => $key, 'title' => "Halaman $key", 'slug' => "halaman-$key"])
                ->call('create')->assertHasNoFormErrors();

            $page = $this->inTenant(fn () => LandingPage::where('slug', "halaman-$key")->firstOrFail());
            $this->assertSame($key, $page->template);
            $this->assertSame('draft', $page->status);
            $this->assertSame(BlockFormat::VERSION, $page->blocks[0]['version']);
            $this->assertArrayHasKey('props', $page->blocks[0]);

            // Rendered through the signed preview, with the sample copy and the lead form.
            $html = $this->get(LandingPageResource::previewUrl($page))->assertOk()->getContent();
            $hero = Templates::blocks($key)[0]['props']['headline'];
            $this->assertStringContainsString(e($hero), $html, $key);
            $this->assertStringContainsString('id="daftar"', $html);
            $this->assertStringContainsString('name="phone"', $html);

            // The editor opens on it and the side preview draws the unsaved state.
            $edit = Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->assertOk();
            $this->assertStringContainsString(e($hero), $edit->instance()->previewHtml());
        }
    }

    public function test_sections_can_be_reordered_hidden_and_the_result_is_what_visitors_see(): void
    {
        $this->admin();
        $page = $this->page(['blocks' => Templates::blocks('kosong')]);

        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->fillForm(['blocks' => [
            ['type' => 'form', 'data' => ['heading' => 'FORM DI ATAS', 'button' => 'Kirim']],
            ['type' => 'price', 'data' => ['label' => 'Harga mulai dari', 'price' => 'Rp 111 juta', 'hidden' => true]],
            ['type' => 'hero', 'data' => ['headline' => 'HERO DI BAWAH']],
        ]])->call('save')->assertHasNoFormErrors();

        $saved = $page->fresh()->blocks;
        $this->assertSame(['form', 'price', 'hero'], array_column($saved, 'type'));
        $this->assertSame([1, 1, 1], array_column($saved, 'version'));

        $html = $this->get('/p/griya-prima/bukit-asri')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'HERO DI BAWAH'), strpos($html, 'FORM DI ATAS'));
        $this->assertStringNotContainsString('Rp 111 juta', $html, 'hidden section is not shown');
    }

    public function test_dangerous_input_never_reaches_the_page(): void
    {
        $evil = '<script>alert(1)</script>';
        $this->page(['blocks' => [
            ['type' => 'hero', 'version' => 1, 'props' => ['headline' => $evil.'Judul', 'subheadline' => '<img src=x onerror=alert(1)>', 'image' => 'landing/999/../../.env']],
            ['type' => 'text', 'version' => 1, 'props' => ['heading' => $evil, 'html' => '<p onclick="x()">Halo <b onmouseover="y()">tebal</b> <a href="javascript:alert(1)">klik</a> <a href="https://contoh.id" onclick="z()">aman</a> <img src=x onerror=alert(1)><script>alert(2)</script><iframe src="https://evil.example"></iframe></p>']],
            ['type' => 'price', 'version' => 1, 'props' => ['price' => '"><script>alert(3)</script>']],
            ['type' => 'amenities', 'version' => 1, 'props' => ['items' => [['icon' => '"><script>', 'title' => $evil, 'text' => 'javascript:alert(1)']]]],
            ['type' => 'location', 'version' => 1, 'props' => ['map_url' => 'https://evil.example/maps/embed?x=1', 'map_link' => 'javascript:alert(1)']],
            ['type' => 'video', 'version' => 1, 'props' => ['url' => 'https://evil.example/watch?v=abcdefghijk']],
            ['type' => 'cta', 'version' => 1, 'props' => ['label' => $evil, 'number' => '08123456789']],
            ['type' => 'script', 'version' => 1, 'props' => ['code' => 'alert(1)']],
        ]]);

        $html = $this->get('/p/griya-prima/bukit-asri')->assertOk()->getContent();

        // The page's own scripts are the only <script> tags; none carries user text.
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('alert(2)', $html, 'script inside rich text is dropped, not shown');
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son(error|click|mouseover|load)\s*=/i', $html, 'no event-handler attributes');
        $this->assertSame(0, preg_match('/<(img|iframe)[^>]*(onerror|evil)/i', $html));
        $this->assertDoesNotMatchRegularExpression('/(href|src)\s*=\s*["\']?\s*javascript:/i', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('.env', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Judul', $html, 'plain text fields are escaped, not dropped');
        $this->assertStringContainsString('<a href="https://contoh.id" rel="noopener nofollow" target="_blank">aman</a>', $html);
        $this->assertStringContainsString('<strong>tebal</strong>', $html);
    }

    public function test_rich_text_keeps_only_basic_tags_and_safe_links(): void
    {
        $this->assertSame('<p>a <strong>b</strong> <em>c</em></p><ul><li>x</li></ul>', RichText::clean('<p class="x">a <b style="x">b</b> <i>c</i></p><ul><li>x</li></ul>'));
        $this->assertSame('x', RichText::clean('<script>evil()</script>x<style>a{}</style>'));
        $this->assertSame('klik', RichText::clean('<a href="javascript:alert(1)">klik</a>'));
        $this->assertSame('klik', RichText::clean('<a href="data:text/html,x">klik</a>'));
        $this->assertSame('klik', RichText::clean('<a href=" JaVaScRiPt:alert(1)">klik</a>'));
        $this->assertStringContainsString('rel="noopener nofollow"', RichText::clean('<a href="http://contoh.id/a?b=1&c=2">x</a>'));
        $this->assertStringContainsString('href="tel:+62812"', RichText::clean('<a href="tel:+62812">x</a>'));
        $this->assertSame('&lt;b&gt;', RichText::clean('&lt;b&gt;'));
        $this->assertSame('', RichText::clean(null));
    }

    public function test_embeds_and_images_are_limited_to_allowed_places(): void
    {
        $this->assertNotNull(Embeds::map('https://www.google.com/maps/embed?pb=!1m18'));
        $this->assertNull(Embeds::map('https://www.google.com.evil.example/maps/embed?pb=1'));
        $this->assertNull(Embeds::map('http://www.google.com/maps/embed?pb=1'));
        $this->assertSame('dQw4w9WgXcQ', Embeds::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', Embeds::youtubeId('https://youtu.be/dQw4w9WgXcQ?t=5'));
        $this->assertNull(Embeds::youtubeId('https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(Embeds::youtubeId('https://vimeo.com/123'));
        $this->assertNull(Embeds::mapLink('https://evil.example/maps'));

        $this->assertNotNull(Embeds::image('landing/5/abc-1.webp', 5));
        $this->assertNull(Embeds::image('landing/6/abc.webp', 5), 'another agency folder');
        $this->assertNull(Embeds::image('landing/5/../6/abc.webp', 5));
        $this->assertNull(Embeds::image('https://evil.example/x.png', 5));
    }

    public function test_embeds_render_only_from_allowed_domains(): void
    {
        $this->page(['blocks' => [
            ['type' => 'location', 'version' => 1, 'props' => ['map_url' => 'https://www.google.com/maps/embed?pb=ok', 'map_link' => 'https://maps.app.goo.gl/abc', 'nearby' => [['place' => 'Tol', 'distance' => '5 menit']]]],
            ['type' => 'video', 'version' => 1, 'props' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
            ['type' => 'kpr', 'version' => 1, 'props' => ['price' => 450000000, 'dp_percent' => 10, 'tenor' => 20, 'rate' => 8]],
        ]]);

        $this->get('/p/griya-prima/bukit-asri')->assertOk()
            ->assertSee('https://www.google.com/maps/embed?pb=ok', false)->assertSee('https://maps.app.goo.gl/abc', false)->assertSee('5 menit')
            ->assertSee('data-yt="dQw4w9WgXcQ"', false)->assertSee('data-kpr', false)->assertSee('Perkiraan cicilan per bulan');
    }

    public function test_countdown_shows_a_timer_until_the_end_then_the_ended_text(): void
    {
        $this->page(['blocks' => [['type' => 'countdown', 'version' => 1, 'props' => ['heading' => 'Promo', 'ends_at' => now()->addDays(3)->format('Y-m-d H:i:s'), 'ended_text' => 'Sudah selesai']]]]);
        $this->get('/p/griya-prima/bukit-asri')->assertOk()->assertSee('data-count=', false)->assertDontSee('<p>Sudah selesai</p>', false);

        DB::table('landing_pages')->update(['blocks' => json_encode([['type' => 'countdown', 'version' => 1, 'props' => ['ends_at' => now()->subDay()->format('Y-m-d H:i:s'), 'ended_text' => 'Sudah selesai']]])]);
        $this->get('/p/griya-prima/bukit-asri')->assertOk()->assertSee('<p>Sudah selesai</p>', false)->assertDontSee('data-count=', false);
    }

    public function test_public_page_is_light_no_external_scripts_and_lazy_images(): void
    {
        $this->page(['blocks' => Templates::blocks('cluster')]);

        $html = $this->get('/p/griya-prima/bukit-asri')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=/i', $html, 'no external script without pixels');
        $this->assertDoesNotMatchRegularExpression('/<link[^>]+stylesheet/i', $html);
        $this->assertLessThan(60_000, strlen($html), 'page html stays small');
        $this->assertStringContainsString('width=device-width', $html);
    }

    public function test_page_settings_font_color_whatsapp_and_seo_are_used(): void
    {
        $this->page(['font' => 'elegan', 'color' => '#0044ff', 'whatsapp_number' => '0812 3456 7890', 'meta_title' => 'Judul Khusus Google', 'description' => 'Deskripsi pratinjau link',
            'blocks' => [['type' => 'cta', 'version' => 1, 'props' => ['label' => 'Hubungi', 'message' => 'Halo kak']]]]);

        $this->get('/p/griya-prima/bukit-asri')->assertOk()
            ->assertSee('<title>Judul Khusus Google</title>', false)->assertSee('og:title" content="Judul Khusus Google"', false)
            ->assertSee('og:description" content="Deskripsi pratinjau link"', false)->assertSee('twitter:card', false)
            ->assertSee('--c: #0044ff', false)->assertSee('Georgia', false)
            ->assertSee('https://wa.me/6281234567890?text=Halo%20kak', false);
    }

    public function test_whatsapp_number_defaults_to_the_agency_channel(): void
    {
        $this->inTenant(fn () => WaChannel::create(['type' => 'official', 'name' => 'Utama', 'phone' => '0811 222 333', 'status' => 'connected', 'position' => 1]));
        $this->page(['blocks' => [['type' => 'cta', 'version' => 1, 'props' => ['label' => 'Chat']]]]);

        $this->get('/p/griya-prima/bukit-asri')->assertOk()->assertSee('https://wa.me/62811222333', false);
    }

    public function test_invalid_color_and_font_fall_back_safely(): void
    {
        $this->page(['font' => 'comic-sans', 'color' => 'red;}', 'blocks' => Templates::blocks('kosong')]);

        $html = $this->get('/p/griya-prima/bukit-asri')->assertOk()->getContent();

        $this->assertStringContainsString('--c: #dc2626', $html);
        $this->assertStringNotContainsString('red;}', $html);
        $this->assertStringContainsString('Inter, system-ui', $html);
    }

    public function test_plan_limit_applies_to_pages_but_all_templates_and_sections_are_open_on_every_plan(): void
    {
        $this->setPlan('mandiri');
        $this->admin();

        // Mandiri allows 1 page; any template (even a premium-looking one) can be the first.
        Livewire::test(CreateLandingPage::class)->fillForm(['template' => 'villa', 'title' => 'Satu', 'slug' => 'satu'])->call('create')->assertHasNoFormErrors();
        $page = $this->inTenant(fn () => LandingPage::firstOrFail());
        $this->assertContains('video', array_column($page->blocks, 'type'));
        $this->assertContains('legal', array_column($page->blocks, 'type'));

        Livewire::test(CreateLandingPage::class)->fillForm(['template' => 'kpr', 'title' => 'Dua', 'slug' => 'dua'])->call('create')->assertNotified('Batas paket tercapai');
        $this->assertSame(1, $this->inTenant(fn () => LandingPage::count()));

        // Duplicating counts as a new page, so it is refused too, and the original stays.
        Livewire::test(ListLandingPages::class)->callTableAction('duplicate', $page)->assertNotified('Batas paket tercapai');
        $this->assertSame(1, $this->inTenant(fn () => LandingPage::count()));

        // Every section can be added on mandiri; only the pixel is locked.
        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->assertOk();
        $this->assertCount(count(Sections::TYPES), Sections::blocks());
    }

    public function test_pixel_stays_locked_on_mandiri_for_every_template(): void
    {
        $this->inTenant(fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890']));
        $this->page(['blocks' => Templates::blocks('kpr')]);

        $this->get('/p/griya-prima/bukit-asri')->assertSee('fbevents.js', false); // trial = tim limits
        $this->setPlan('mandiri');
        $this->get('/p/griya-prima/bukit-asri')->assertOk()->assertDontSee('fbevents.js', false);
    }

    public function test_duplicate_makes_an_independent_draft_copy(): void
    {
        $this->admin();
        $page = $this->page(['blocks' => Templates::blocks('second'), 'views' => 40]);

        Livewire::test(ListLandingPages::class)->callTableAction('duplicate', $page)->assertNotified('Halaman diduplikat sebagai draf');
        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->callAction('duplicate');

        $copies = $this->inTenant(fn () => LandingPage::where('id', '!=', $page->getKey())->orderBy('id')->get());
        $this->assertCount(2, $copies);
        $this->assertSame(['bukit-asri-salinan', 'bukit-asri-salinan-2'], $copies->pluck('slug')->all());
        $this->assertSame(['draft', 'draft'], $copies->pluck('status')->all());
        $this->assertSame([0, 0], $copies->pluck('views')->all());
        $this->assertSame($page->blocks, $copies[0]->blocks);
        $this->get('/p/griya-prima/bukit-asri-salinan')->assertNotFound();
    }

    public function test_pages_saved_before_the_new_format_still_show_and_get_converted(): void
    {
        $page = $this->page(['blocks' => [
            ['type' => 'hero', 'data' => ['headline' => 'Judul lama']],
            ['type' => 'text', 'data' => ['heading' => 'Tentang', 'body' => "Baris <b>satu</b>\n\nParagraf dua"]],
            ['type' => 'whatsapp', 'data' => ['number' => '08123456789', 'label' => 'Chat lama', 'message' => 'Halo']],
            ['type' => 'form', 'data' => ['heading' => 'Daftar lama', 'button' => 'Kirim']],
        ]]);

        // Readable as is...
        $this->get('/p/griya-prima/bukit-asri')->assertOk()->assertSee('Judul lama')->assertSee('Paragraf dua')->assertSee('Chat lama')->assertSee('Daftar lama')
            ->assertDontSee('<b>satu</b>', false)->assertSee('https://wa.me/628123456789', false);

        // ...and the data migration rewrites it once, keeping the content.
        DB::table('landing_pages')->where('id', $page->getKey())->update(['blocks' => json_encode($page->blocks)]);
        (include base_path('database/migrations/2026_10_18_000100_landing_builder_columns_and_blocks_v1.php'))->up();

        $converted = DB::table('landing_pages')->where('id', $page->getKey())->value('blocks');
        $converted = json_decode($converted, true);
        $this->assertSame(['hero', 'text', 'cta', 'form'], array_column($converted, 'type'));
        $this->assertSame([1, 1, 1, 1], array_column($converted, 'version'));
        $this->assertSame('Judul lama', $converted[0]['props']['headline']);
        $this->assertStringContainsString('Paragraf dua', $converted[1]['props']['html']);
        $this->assertStringNotContainsString('<b>', $converted[1]['props']['html']);
        $this->assertArrayNotHasKey('data', $converted[0]);

        // Running it again changes nothing.
        (include base_path('database/migrations/2026_10_18_000100_landing_builder_columns_and_blocks_v1.php'))->up();
        $this->assertSame($converted, json_decode(DB::table('landing_pages')->where('id', $page->getKey())->value('blocks'), true));
        $this->get('/p/griya-prima/bukit-asri')->assertOk()->assertSee('Judul lama');
    }

    public function test_editor_round_trips_the_stored_format(): void
    {
        $this->admin();
        $page = $this->page(['blocks' => [['type' => 'hero', 'data' => ['headline' => 'Format lama']]]]);

        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->assertFormSet(fn ($state) => $state['blocks'] !== [])->call('save')->assertHasNoFormErrors();

        $saved = $page->fresh()->blocks;
        $this->assertCount(1, $saved);
        $this->assertSame(['type', 'version', 'props'], array_keys($saved[0]));
        $this->assertSame(['hero', 1, 'Format lama'], [$saved[0]['type'], $saved[0]['version'], $saved[0]['props']['headline']]);
    }

    public function test_preview_needs_a_signature_and_hidden_pages_are_not_public(): void
    {
        $page = $this->page(['status' => 'draft', 'blocks' => Templates::blocks('kpr')]);

        $this->get('/p/griya-prima/bukit-asri')->assertNotFound();
        $this->get('/p/griya-prima/bukit-asri/pratinjau')->assertForbidden();
        $this->get(LandingPageResource::previewUrl($page))->assertOk()->assertSee('noindex', false);
    }

    public function test_lead_from_a_published_template_page_reaches_the_crm(): void
    {
        $page = $this->page(['blocks' => Templates::blocks('kpr'), 'title' => 'Promo KPR']);

        $html = $this->get('/p/griya-prima/bukit-asri')->assertOk()->getContent();
        $this->assertStringContainsString('data-endpoint="'.$this->tenant->captureUrl().'"', $html);
        $this->assertStringContainsString('data-page="'.$page->getKey().'"', $html);

        $this->postJson("/capture/{$this->token}", ['name' => 'Sinta', 'phone' => '0812 7777 8888', 'page' => $page->getKey()])->assertOk();

        $lead = $this->inTenant(fn () => Lead::firstOrFail());
        $this->assertSame('Landing: Promo KPR', $this->inTenant(fn () => $lead->source->name));
    }

    public function test_images_are_shrunk_to_1600px_and_converted(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'img');
        $im = imagecreatetruecolor(3200, 2000);
        imagefilledrectangle($im, 0, 0, 3200, 2000, imagecolorallocate($im, 220, 38, 38));
        imagepng($im, $file);

        $out = ImageOptimizer::encode($file);

        $this->assertNotNull($out);
        $this->assertSame(function_exists('imagewebp') ? 'webp' : 'png', $out['ext']);
        [$w, $h] = getimagesizefromstring($out['bytes']);
        $this->assertSame(1600, $w);
        $this->assertSame(1000, $h);
        $this->assertLessThan(filesize($file), strlen($out['bytes']));

        $this->assertNull(ImageOptimizer::encode(__FILE__), 'not an image');
        $small = ImageOptimizer::encode($file, 600);
        $this->assertSame(600, getimagesizefromstring($small['bytes'])[0]);
        unlink($file);
    }

    public function test_upload_fields_accept_only_safe_image_types_within_the_size_limit(): void
    {
        $field = Sections::upload('image');

        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], $field->getAcceptedFileTypes());
        $this->assertSame(8192, $field->getMaxSize());
        $this->assertLessThanOrEqual(10 * 1024, $field->getMaxSize(), 'stays under the 10M nginx body limit');
    }
}
