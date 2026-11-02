<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Filament\Resources\LandingPages\LandingPageResource;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\LandingPages\BlockFormat;
use App\LandingPages\BlockStyle;
use App\LandingPages\Templates;
use App\Models\LandingPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/** Conversion templates, the new sections (incl. slider and icon list) and the per-section "Gaya" panel. */
class LandingConversionStyleTest extends TestCase
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

    private function page(array $blocks, ?Tenant $tenant = null): LandingPage
    {
        return $this->in($tenant ?? $this->tenant, fn () => LandingPage::create([
            'title' => 'Uji', 'slug' => 'uji', 'status' => 'published', 'color' => '#dc2626', 'blocks' => BlockFormat::normalize($blocks),
        ]));
    }

    private function b(string $type, array $props): array
    {
        return ['type' => $type, 'version' => BlockFormat::VERSION, 'props' => $props];
    }

    private function html(?Tenant $tenant = null): string
    {
        return $this->get('/p/'.($tenant ?? $this->tenant)->slug.'/uji')->assertOk()->getContent();
    }

    private function img(string $name, ?Tenant $tenant = null): string
    {
        return 'landing/'.($tenant ?? $this->tenant)->getKey().'/'.$name;
    }

    // ---- Templates -----------------------------------------------------------------------------------------------

    public function test_every_conversion_template_is_created_in_the_conversion_order_and_renders(): void
    {
        $cases = [
            [$this->shop, 'konversi_produk', true], [$this->shop, 'konversi_produk_wa', false], [$this->shop, 'konversi_layanan', true],
            [$this->tenant, 'konversi_properti', true], [$this->tenant, 'konversi_properti_wa', false], [$this->tenant, 'konversi_layanan', true],
        ];

        foreach ($cases as [$tenant, $key, $form]) {
            $this->actingInTenant($this->adminOf($tenant), $tenant);
            $slug = str_replace('_', '-', $key);
            Livewire::test(CreateLandingPage::class)->fillForm(['template' => $key, 'title' => "Halaman $key", 'slug' => $slug])->call('create')->assertHasNoFormErrors();

            $page = $this->in($tenant, fn () => LandingPage::where('slug', $slug)->firstOrFail());
            $types = array_values(array_diff(array_column($page->blocks, 'type'), ['icon_list']));
            $this->assertSame(['hero', 'problem_solution', 'benefits', 'social_proof', 'faq', 'closing', ...($form ? ['form'] : [])], $types, $key);

            $html = $this->get(LandingPageResource::previewUrl($page))->assertOk()->getContent();
            $this->assertStringContainsString('[Masalah pertama]', $html, $key);
            $this->assertStringContainsString('[Manfaat utama]', $html, $key);
            $this->assertStringContainsString(' closing"><div class="wrap cta">', $html, $key);
            $this->assertStringNotContainsString('class="dock"', $html, $key);
            $this->assertStringNotContainsString('class="waf"', $html, $key);

            if ($form) {
                $this->assertStringContainsString('name="f[whatsapp]"', $html, $key);
                $this->assertStringContainsString('href="#daftar" data-action="scroll"', $html, $key);
            } else {
                $this->assertStringNotContainsString('name="f[whatsapp]"', $html, $key);
                $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="'.route('go.wa', $tenant->slug).'?text='), $key);
            }

            // Placeholders, never made-up numbers or claims.
            $json = json_encode($page->blocks, JSON_UNESCAPED_UNICODE);
            $this->assertDoesNotMatchRegularExpression('/\d+\s?%|\d{2,}\+/', $json, $key);

            Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->assertOk()->call('save')->assertHasNoFormErrors();
        }

        $shop = json_encode(Templates::blocks('konversi_produk'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('🚚', $shop);
        $this->assertStringContainsString('Admin 05.00–21.00', $shop);
        $this->assertArrayNotHasKey('konversi_produk', Templates::options('properti'));
        $this->assertArrayNotHasKey('konversi_properti', Templates::options('retail'));
    }

    // ---- Sections ------------------------------------------------------------------------------------------------

    public function test_new_sections_render_their_content(): void
    {
        $this->page([
            $this->b('hero', ['headline' => 'Judul', 'visual' => 'video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'assurance' => 'Gratis ongkir • Bisa COD',
                'buttons' => [['label' => 'Pesan', 'action' => 'scroll'], ['label' => 'Tanya', 'action' => 'tel', 'tel' => '0812345678', 'color' => 'outline']]]),
            $this->b('problem_solution', ['heading' => 'Masalah', 'problem_title' => 'Dulu', 'solution_title' => 'Sekarang',
                'problems' => [['icon' => '😩', 'text' => 'Capek antre']], 'solutions' => [['icon' => '', 'text' => 'Pesan dari rumah']]]),
            $this->b('benefits', ['heading' => 'Manfaat', 'items' => [['icon_type' => 'emoji', 'emoji' => '😴', 'title' => 'Tidur nyenyak', 'text' => 'Tanpa pegal']]]),
            $this->b('social_proof', ['heading' => 'Bukti', 'testimonials' => [['name' => 'Rina', 'role' => 'Bandung', 'quote' => 'Mantap', 'rating' => 4, 'photo' => $this->img('a.jpg')]],
                'logos' => [$this->img('logo.png')], 'logos_title' => 'Diliput oleh', 'stats' => [['number' => '1.200', 'label' => 'pembeli']]]),
            $this->b('closing', ['heading' => 'Ayo pesan', 'assurances' => ['Garansi 7 hari', 'Bisa COD'], 'buttons' => [['label' => 'Pesan sekarang', 'action' => 'scroll']]]),
            $this->b('form', []),
        ]);

        $html = $this->html();
        $this->assertStringContainsString('data-yt="dQw4w9WgXcQ"', $html);
        $this->assertStringContainsString('<p class="assure">Gratis ongkir • Bisa COD</p>', $html);
        $this->assertStringContainsString('href="tel:+62812345678"', $html, 'second hero button');
        $this->assertStringContainsString('😩</span><span>Capek antre', $html);
        $this->assertStringContainsString('✓</span><span>Pesan dari rumah', $html, 'default tick');
        $this->assertStringContainsString('😴</span>', $html);
        $this->assertStringContainsString('<b>Rina</b><small>Bandung</small>', $html);
        $this->assertStringContainsString('★★★★<span>★</span>', $html);
        $this->assertStringContainsString('/storage/'.$this->img('logo.png'), $html);
        $this->assertStringContainsString('<b>1.200</b><span>pembeli</span>', $html);
        $this->assertStringContainsString('<li>Garansi 7 hari</li>', $html);
    }

    public function test_hero_plays_an_mp4_and_can_use_a_slider(): void
    {
        $this->page([$this->b('hero', ['headline' => 'Judul', 'visual' => 'video', 'video_file' => $this->img('klip.mp4')])]);
        $this->assertStringContainsString('<video class="hero-vid" src="'.url('/storage/'.$this->img('klip.mp4')).'"', $this->html());

        $this->in($this->tenant, fn () => LandingPage::first()->update(['blocks' => [$this->b('hero', ['headline' => 'Judul', 'visual' => 'slider',
            'slider_slides' => [['image' => $this->img('s1.jpg'), 'alt' => 'Depan'], ['image' => $this->img('s2.jpg'), 'alt' => 'Samping']]])]]));
        $html = $this->html();
        $this->assertStringContainsString('class="hero hero-m"', $html);
        $this->assertStringContainsString('alt="Depan" decoding="async"  fetchpriority="high"', $html);
        $this->assertStringContainsString('alt="Samping" decoding="async"  loading="lazy"', $html);
    }

    public function test_slider_settings_are_drawn(): void
    {
        $this->page([$this->b('slider', ['heading' => 'Galeri', 'autoplay' => true, 'interval' => 4, 'arrows' => true, 'dots' => true, 'loop' => false,
            'per_mobile' => 1, 'per_tablet' => 2, 'per_desktop' => 3, 'gap' => 16, 'effect' => 'slide', 'ratio' => '4:3', 'fit' => 'contain', 'radius' => 20,
            'slides' => [
                ['image' => $this->img('1.jpg'), 'alt' => 'Satu', 'title' => 'Judul satu', 'caption' => 'Ket', 'link' => 'https://contoh.id/a'],
                ['image' => $this->img('2.jpg'), 'alt' => 'Dua', 'has_button' => true, 'button' => ['label' => 'Beli', 'action' => 'scroll']],
                ['image' => 'landing/999/lain.jpg', 'alt' => 'Bukan milik agensi ini'],
                ['image' => $this->img('3.jpg'), 'alt' => 'Tiga', 'link' => 'javascript:alert(1)'],
            ]])]);

        $html = $this->html();
        $this->assertStringContainsString('data-auto="4"', $html);
        $this->assertStringNotContainsString('data-loop', explode('style=', explode('<div class="sld', $html)[1])[0]);
        $this->assertStringContainsString('--pvm: 1; --pvt: 2; --pvd: 3; --sg: 16px; --sr: 20px; --sf: contain;  --sar: 4/3;', $html);
        $this->assertSame(3, substr_count($html, 'class="slide '), 'only this agency\'s pictures');
        $this->assertStringNotContainsString('Bukan milik agensi ini', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<a href="https://contoh.id/a"', $html);
        $this->assertMatchesRegularExpression('#<strong>Judul satu</strong>\s*<span>Ket</span>#', $html);
        $this->assertStringContainsString('<span>Beli</span>', $html);
        $this->assertSame(3, substr_count($html, 'aria-label="Ke slide'));
        $this->assertStringContainsString('class="sl-arrow prev"', $html);
        $this->assertSame(2, substr_count($html, 'loading="lazy"'), 'only the first picture of the first section loads at once');
        $this->assertStringContainsString('[data-slider]', $html, 'its script is on the page');

        $this->in($this->tenant, fn () => LandingPage::first()->update(['blocks' => [$this->b('slider', ['effect' => 'fade', 'per_desktop' => 4, 'arrows' => false, 'dots' => false,
            'slides' => [['image' => $this->img('1.jpg')], ['image' => $this->img('2.jpg')]]])]]));
        $html = $this->html();
        $this->assertStringContainsString('class="sld fade"', $html);
        $this->assertStringContainsString('--pvd: 1;', $html, 'fade shows one slide');
        $this->assertStringNotContainsString('class="sl-arrow', $html);
        $this->assertStringNotContainsString('class="sl-dots"', $html);
    }

    public function test_icon_list_draws_icons_emoji_and_pictures_with_its_layout(): void
    {
        $this->page([$this->b('icon_list', ['heading' => 'Keunggulan', 'layout' => 'grid', 'cols_desktop' => 4, 'cols_mobile' => 2, 'icon_size' => 40,
            'icon_shape' => 'square', 'icon_color' => '#ff0000', 'icon_bg' => 'red;}', 'divider' => true, 'items' => [
                ['icon_type' => 'emoji', 'emoji' => '🚚', 'title' => 'Gratis ongkir', 'text' => 'Min. belanja'],
                ['icon_type' => 'icon', 'icon' => 'cash', 'title' => 'Bisa COD'],
                ['icon_type' => 'image', 'icon_image' => $this->img('ikon.png'), 'title' => 'Retur', 'link' => 'https://contoh.id/retur'],
            ]])]);

        $html = $this->html();
        $this->assertStringContainsString('class="ic ic-grid ic-pos-top ic-square ic-div" style="--icm: 2; --icd: 4; --ics: 40px; --icc: #ff0000;"', $html);
        $this->assertStringContainsString('🚚</span>', $html);
        $this->assertStringContainsString('<svg', explode('Bisa COD', $html)[0]);
        $this->assertStringContainsString('class="ic-img" src="'.url('/storage/'.$this->img('ikon.png')).'"', $html);
        $this->assertStringContainsString('href="https://contoh.id/retur"', $html);
        $this->assertStringNotContainsString('red;}', $html);

        $this->in($this->tenant, fn () => LandingPage::first()->update(['blocks' => [$this->b('icon_list', ['layout' => 'list', 'icon_pos' => 'right', 'items' => [['title' => 'Satu']],
            'style' => ['align' => 'center', 'mobile' => ['align' => 'left'], 'hide_desktop' => true]])]]));
        $html = $this->html();
        $this->assertStringContainsString('class="ic ic-list ic-pos-right ic-circle "', $html);
        $this->assertStringContainsString('@media (min-width:768px){#bx-1{display:none}}', $html, 'hidden on PC');
        $this->assertStringContainsString('@media (max-width:767px){#bx-1>section,#bx-1 .wrap,#bx-1 .card,#bx-1 .formbox{text-align:left}', $html, 'phone alignment');
    }

    // ---- Style panel ---------------------------------------------------------------------------------------------

    public function test_style_is_scoped_to_its_section_and_the_phone_overrides_the_desktop(): void
    {
        $this->page([
            $this->b('hero', ['headline' => 'Judul']),
            $this->b('amenities', ['heading' => 'Fasilitas', 'items' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']], 'style' => [
                'align' => 'center', 'heading_size' => 'xl', 'text_px' => 18, 'heading_color' => '#112233', 'line_height' => 1.8,
                'bg_type' => 'gradient', 'bg_color' => '#ffeeaa', 'bg_color2' => '#aabbcc', 'bg_angle' => 90,
                'pad_top' => 80, 'pad_bottom' => 20, 'gap' => 24, 'width' => 'narrow', 'columns' => '3', 'valign' => 'center',
                'btn_size' => 'l', 'btn_style' => 'outline', 'btn_color' => '#0000ff', 'btn_radius' => 30, 'btn_icon_pos' => 'right', 'btn_full_mobile' => true,
                'animation' => 'fade',
                'mobile' => ['columns' => '1', 'heading_px' => 26, 'pad_top' => 24, 'align' => 'left'],
            ]]),
            $this->b('gallery', ['heading' => 'Foto', 'images' => [$this->img('g.jpg')], 'style' => [
                'img_width' => 60, 'img_max_h' => 400, 'ratio' => '16:9', 'fit' => 'contain', 'radius' => 24, 'shadow' => true, 'img_align' => 'center',
                'mobile' => ['img_width' => 100, 'ratio' => '1:1'],
            ]]),
        ]);

        $html = $this->html();
        $this->assertStringContainsString('<div class="bx bx-anim bx-fade" id="bx-2">', $html);
        $this->assertStringContainsString('<div class="bx bx-plain" id="bx-3">', $html);
        $this->assertStringNotContainsString('id="bx-1"', $html, 'a section without style is drawn as before');

        $css = explode('</style>', explode('<style>#bx-', $html)[1])[0];
        $this->assertStringContainsString('2>section,#bx-2 .wrap,#bx-2 .card,#bx-2 .formbox{text-align:center}', $css);
        $this->assertStringContainsString('#bx-2 h1,#bx-2 h2{font-size:2.75rem}', $css);
        $this->assertStringContainsString('#bx-2 p,#bx-2 li,#bx-2 blockquote,#bx-2 summary,#bx-2 td,#bx-2 th{font-size:18px}', $css);
        $this->assertStringContainsString('#bx-2>section{background:linear-gradient(90deg,#ffeeaa,#aabbcc)}', $css);
        $this->assertStringContainsString('#bx-2 .wrap{max-width:44rem}', $css);
        $this->assertStringContainsString('padding-top:80px', $css);
        $this->assertStringContainsString('#bx-2 .jb{padding:1rem 1.6rem;font-size:1.1rem;min-height:3.4rem;background:transparent;color:#0000ff;border-color:currentColor;border-radius:30px;flex-direction:row-reverse}', $css);
        $this->assertStringContainsString('@media (min-width:768px){#bx-2 .grid,#bx-2 .cols{grid-template-columns:repeat(3,minmax(0,1fr))}}', $css);
        $this->assertStringContainsString('#bx-3 img:not(.bg):not(.ic-img){width:60%;max-height:400px;aspect-ratio:16/9;object-fit:contain;border-radius:24px;box-shadow:', $css);

        // Phone values come after, inside the phone query, so they win below 768px.
        $phone = explode('@media (max-width:767px){', $css);
        $this->assertCount(3, $phone);
        $this->assertStringContainsString('#bx-2 h1,#bx-2 h2{font-size:26px}', $phone[1]);
        $this->assertStringContainsString('padding-top:24px', $phone[1]);
        $this->assertStringContainsString('#bx-2 .grid,#bx-2 .cols{grid-template-columns:repeat(1,minmax(0,1fr))}', $phone[1]);
        $this->assertStringContainsString('text-align:left', $phone[1]);
        $this->assertStringContainsString('#bx-2 .jb{width:100%}', $phone[1]);
        $this->assertStringContainsString('#bx-3 img:not(.bg):not(.ic-img){width:100%;aspect-ratio:1/1}', $phone[2]);
        $this->assertStringContainsString("classList.add('js')", $html, 'animation needs its script');
    }

    public function test_style_values_are_checked_before_reaching_the_stylesheet(): void
    {
        $clean = BlockStyle::clean([
            'align' => 'justify', 'text_color' => 'red;}body{display:none', 'heading_px' => 500, 'pad_top' => -40, 'columns' => '7', 'bg_type' => 'image',
            'bg_image' => 'landing/999/lain.jpg', 'overlay' => 200, 'unknown' => 'x', 'mobile' => ['align' => '</style>'],
        ]);
        $this->assertSame(['heading_px' => 96.0, 'pad_top' => 0.0, 'bg_type' => 'image', 'overlay' => 90.0, 'bg_image' => 'landing/999/lain.jpg'], $clean);

        $css = BlockStyle::css('#bx-1', $clean, $this->tenant->getKey());
        $this->assertStringNotContainsString('url(', $css, 'another agency\'s picture is not used');
        $this->assertStringNotContainsString('<', $css);
        $this->assertSame('', BlockStyle::css('#bx-1', ['align' => '', 'mobile' => []], 1));
        $this->assertFalse(BlockStyle::has(['bg_type' => '', 'shadow' => false]));

        $own = BlockStyle::css('#bx-1', ['bg_type' => 'image', 'bg_image' => $this->img('latar.jpg'), 'overlay' => 50, 'bg_position' => 'top'], $this->tenant->getKey());
        $this->assertStringContainsString('linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5)),url("'.url('/storage/'.$this->img('latar.jpg')).'") top/cover no-repeat', $own);
        $this->assertStringContainsString('#bx-1>section{color:#fff}', $own, 'readable text on a picture');
    }

    public function test_pages_without_style_render_as_before(): void
    {
        $page = $this->page(Templates::blocks('kpr'));
        $html = $this->html();

        $this->assertStringNotContainsString('id="bx-', $html);
        $this->assertStringNotContainsString('<style>#bx', $html);
        $this->assertStringNotContainsString("classList.add('js')", $html);
        $this->assertStringContainsString('<section class="hero">', $html);
        $this->assertSame(BlockFormat::normalize($page->blocks), BlockFormat::normalize(Templates::blocks('kpr')));
    }

    public function test_the_editor_saves_style_and_copies_pastes_and_resets_it(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $page = $this->page([$this->b('text', ['heading' => 'Satu', 'html' => '<p>a</p>']), $this->b('text', ['heading' => 'Dua', 'html' => '<p>b</p>'])]);

        $lw = Livewire::test(EditLandingPage::class, ['record' => $page->getKey()]);
        $keys = array_keys($lw->get('data.blocks'));
        $lw->set("data.blocks.{$keys[0]}.data.style.align", 'center')
            ->set("data.blocks.{$keys[0]}.data.style.img_width", 50)
            ->set("data.blocks.{$keys[0]}.data.style.mobile.align", 'right')
            ->call('save')->assertHasNoFormErrors();

        $saved = $page->fresh()->blocks;
        $this->assertSame('center', $saved[0]['props']['style']['align']);
        $this->assertSame('right', $saved[0]['props']['style']['mobile']['align']);
        $this->assertStringContainsString('#bx-1 img:not(.bg):not(.ic-img){width:50%}', $this->html());

        $lw->callAction(TestAction::make('copyStyle')->schemaComponent('blocks')->arguments(['item' => $keys[0]]))
            ->callAction(TestAction::make('pasteStyle')->schemaComponent('blocks')->arguments(['item' => $keys[1]]))
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('center', $page->fresh()->blocks[1]['props']['style']['align']);

        $lw->callAction(TestAction::make('resetStyle')->schemaComponent('blocks')->arguments(['item' => $keys[0]]))
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse(BlockStyle::has($page->fresh()->blocks[0]['props']['style'] ?? null));
        $html = $this->html();
        $this->assertStringNotContainsString('id="bx-1"', $html);
        $this->assertStringContainsString('id="bx-2"', $html);
    }

    public function test_at_360px_wide_sections_keep_one_column_unless_set(): void
    {
        $css = BlockStyle::css('#bx-1', ['columns' => '4', 'media_pos' => 'left'], 1);
        $this->assertStringStartsWith('@media (min-width:768px){', $css, 'desktop layout never reaches phones');
        $this->assertStringNotContainsString('max-width:767px', $css);
    }
}
