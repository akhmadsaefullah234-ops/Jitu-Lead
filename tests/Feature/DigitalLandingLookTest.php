<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Console\Commands\SeedLudoPasutriCommand;
use App\Filament\Resources\LandingPages\LandingPageResource;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\LandingPages\BlockFormat;
use App\LandingPages\PageLayout;
use App\LandingPages\PageTheme;
use App\LandingPages\Sections;
use App\LandingPages\Templates;
use App\Models\LandingPage;
use App\Models\LeadForm;
use App\Models\PaymentGateway;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Prompt I: the dark theme, the new "Produk digital" sections, landing:ludo-pasutri --refresh and the payment choices. */
class DigitalLandingLookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $digital;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->digital = app(ProvisionTenant::class)('Toko Digital', 'toko-digital', User::factory()->create(), 'digital');
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->digital, $fn);
    }

    private function ludo(): LandingPage
    {
        Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital']);

        return $this->in(fn () => LandingPage::query()->where('slug', SeedLudoPasutriCommand::PAGE_SLUG)->firstOrFail());
    }

    /** A real, small PNG in this agency's upload folder. */
    private function picture(string $name, int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);
        $path = 'landing/'.$this->digital->getKey().'/'.$name;
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function publish(LandingPage $page): string
    {
        $this->in(fn () => $page->update(['status' => 'published', 'published_at' => now()]));

        return $this->get('/p/toko-digital/'.$page->slug)->assertOk()->getContent();
    }

    public function test_the_dark_theme_prints_its_colours_and_a_light_page_prints_none(): void
    {
        $html = $this->publish($this->ludo());

        foreach (['--bg:#171020', '--card:#221731', '--card2:#2d2040', '--ink:#f5ecf4', '--mute:#b9a9c4', '--line:#46345f', '--ac:#e9c27b', '--on-ac:#171020'] as $var) {
            $this->assertStringContainsString($var, $html);
        }

        $page = $this->in(fn () => LandingPage::query()->firstOrFail());
        $this->in(fn () => $page->update(['layout' => null]));
        $light = $this->get('/p/toko-digital/'.$page->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('--on-ac', $light);
        $this->assertStringNotContainsString('#171020', $light);
    }

    public function test_the_theme_keeps_only_known_values_and_an_old_layout_stays_as_it_was(): void
    {
        $this->assertSame([], PageLayout::clean(null));
        $this->assertSame(['spacing' => 'tight'], PageLayout::clean(['spacing' => 'tight', 'theme' => 'terang']));
        $this->assertSame(['theme' => 'gelap', 'accent' => '#abcdef'], PageLayout::clean(['theme' => 'gelap', 'accent' => '#ABCDEF']));
        $this->assertSame(['theme' => 'gelap'], PageLayout::clean(['theme' => 'gelap', 'accent' => 'red;}body{display:none']));
        $this->assertSame('', PageTheme::css(['spacing' => 'tight']));
        $this->assertSame('', PageLayout::css(['theme' => 'gelap']), 'the theme adds nothing to the spacing CSS');
    }

    public function test_text_on_the_accent_and_on_the_dark_cards_meets_wcag_aa(): void
    {
        foreach (['#e9c27b', '#ffffff', '#000000', '#5b2a86', '#22c55e', '#2563eb', '#f97316', '#777777'] as $accent) {
            $this->assertGreaterThanOrEqual(4.5, PageTheme::contrast($accent, PageTheme::onAccent($accent)), "button text on {$accent}");
        }

        $this->assertSame('#171020', PageTheme::onAccent(PageTheme::ACCENT), 'dark text on the default gold');

        $d = PageTheme::DARK;
        foreach (['bg', 'card', 'card2'] as $surface) {
            foreach ([$d['ink'], $d['mute'], PageTheme::ACCENT] as $text) {
                $this->assertGreaterThanOrEqual(4.5, PageTheme::contrast($text, $d[$surface]), "{$text} on {$surface}");
            }
        }
    }

    public function test_the_new_sections_are_for_digital_agencies_only(): void
    {
        foreach (['kind_cards', 'phone_shots'] as $type) {
            $this->assertArrayHasKey($type, Sections::types('digital'));
            $this->assertArrayNotHasKey($type, Sections::types('properti'));
            $this->assertArrayNotHasKey($type, Sections::types('retail'));
        }
    }

    public function test_empty_kind_cards_and_phone_shots_are_not_drawn(): void
    {
        $blocks = [
            ['type' => 'kind_cards', 'props' => ['heading' => 'Jenis', 'items' => []]],
            ['type' => 'phone_shots', 'props' => ['heading' => 'HP', 'items' => [['caption' => 'tanpa gambar']]]],
            ['type' => 'text', 'props' => ['heading' => 'Tetap', 'html' => '<p>x</p>']],
        ];
        $this->assertSame(['text'], array_column(BlockFormat::visible($blocks), 'type'));

        $html = $this->publish($this->ludo());
        $this->assertStringContainsString('class="kc-g n4"', $html);
        $this->assertStringNotContainsString('class="phones', $html, 'the seeded page has no screenshots yet');
        $this->assertStringNotContainsString('Tampilan di HP', $html);
        foreach (['Sentuhan', 'Truth', 'Dare', 'Fantasi'] as $kind) {
            $this->assertStringContainsString('<h3>'.$kind.'</h3>', $html);
        }
        $this->assertStringContainsString('<blockquote>&ldquo;Pijat bahu pasangan selama satu menit.&rdquo;</blockquote>', $html);
    }

    public function test_phone_shots_and_the_hero_picture_have_sizes_alt_text_and_lazy_loading(): void
    {
        $page = $this->ludo();
        $shot = $this->picture('layar.png', 90, 190);
        $hero = $this->picture('produk.png', 120, 160);
        $blocks = collect($page->blocks)->map(function ($b) use ($shot, $hero) {
            if ($b['type'] === 'phone_shots') {
                $b['props']['items'] = [['image' => $shot, 'caption' => 'Papan permainan', 'alt' => 'Papan permainan di layar HP'], ['image' => 'landing/999/lain.png', 'caption' => 'Bukan milik agensi ini']];
            }
            if ($b['type'] === 'hero') {
                $b['props']['image'] = $hero;
            }

            return $b;
        })->all();
        $this->in(fn () => $page->update(['blocks' => $blocks]));

        $html = $this->publish($page->refresh());
        $this->assertMatchesRegularExpression('#<img src="[^"]+/landing/\d+/layar\.png" alt="Papan permainan di layar HP" loading="lazy" decoding="async"\s+width="90" height="190"\s*>#', $html);
        $this->assertStringContainsString('<figcaption>Papan permainan</figcaption>', $html);
        $this->assertStringNotContainsString('lain.png', $html, 'only this agency\'s own pictures');
        $this->assertStringNotContainsString('Bukan milik agensi ini', $html);
        $this->assertStringContainsString('class="hero hero-m hero-p"', $html);
        $this->assertMatchesRegularExpression('#<img class="hero-shot" src="[^"]+/produk\.png" alt="Tampilan permainan Ludo Pasutri di layar HP" fetchpriority="high" decoding="async"\s+width="120" height="160"#', $html);
        $this->assertStringNotContainsString('<img class="bg"', $html, 'the product picture is not a background');
    }

    public function test_tiers_are_stepped_and_the_bonus_ribbon_shows_the_product_price(): void
    {
        $page = $this->ludo();
        $html = $this->publish($page);

        $this->assertStringContainsString('<ol class="tiers n3">', $html);
        $this->assertStringContainsString('style="--step:2"', $html);
        $this->assertStringContainsString('Tingkat 3 dari 3', $html);
        $this->assertStringContainsString('<p class="ribbon"><span>Sudah termasuk di harga Rp99.000</span></p>', $html);

        $this->in(fn () => Property::query()->where('name', SeedLudoPasutriCommand::PRODUCT)->update(['compare_price' => 149000]));
        $html = $this->get('/p/toko-digital/'.$page->slug)->assertOk()->getContent();
        $this->assertStringContainsString('<span>Sudah termasuk di harga Rp99.000</span><span>Harga normal <s>Rp149.000</s></span>', $html);
    }

    public function test_a_page_made_from_the_template_is_dark(): void
    {
        $data = $this->in(fn () => CreateLandingPage::fromTemplate(['template' => Templates::DIGITAL, 'title' => 'Kelas', 'slug' => 'kelas', 'order_form' => 'new']));

        $this->assertSame(['theme' => 'gelap', 'accent' => '#e9c27b'], $data['layout']);

        $retail = app(ProvisionTenant::class)('Toko Baju', 'toko-baju', User::factory()->create(), 'retail');
        $other = $this->in(fn () => CreateLandingPage::fromTemplate(['template' => 'produk_promo', 'title' => 'Promo', 'slug' => 'promo']), $retail);
        $this->assertArrayNotHasKey('layout', $other, 'other templates are made as before');
    }

    public function test_refresh_updates_only_the_seeders_draft_and_keeps_its_pictures(): void
    {
        $page = $this->ludo();
        $hero = $this->picture('produk.png', 40, 40);
        $old = collect($page->blocks)->reject(fn ($b) => in_array($b['type'], ['kind_cards', 'phone_shots'], true))
            ->map(fn ($b) => $b['type'] === 'hero' ? array_replace_recursive($b, ['props' => ['image' => $hero]]) : $b)->values()->all();
        $this->in(fn () => $page->update(['blocks' => $old, 'layout' => ['spacing' => 'tight'], 'title' => 'Judul saya']));

        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'digital');
        $this->in(fn () => LandingPage::create(['title' => 'Lain', 'slug' => SeedLudoPasutriCommand::PAGE_SLUG, 'status' => 'draft', 'blocks' => [], 'color' => '#111111', 'font' => 'modern']), $other);

        // Without --refresh nothing changes.
        $this->assertSame(0, Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital']));
        $this->assertNotContains('kind_cards', array_column($page->refresh()->blocks, 'type'));

        $this->assertSame(0, Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital', '--refresh' => true]));
        $page->refresh();
        $this->assertContains('kind_cards', array_column($page->blocks, 'type'));
        $this->assertSame($hero, collect($page->blocks)->firstWhere('type', 'hero')['props']['image']);
        $this->assertSame(['spacing' => 'tight', 'theme' => 'gelap', 'accent' => '#e9c27b'], $page->layout);
        $this->assertSame('Judul saya', $page->title);
        $this->assertSame('draft', $page->status);
        $this->assertSame([], $this->in(fn () => LandingPage::query()->firstOrFail()->blocks, $other), 'another agency\'s page is left alone');
        $this->assertSame(1, $this->in(fn () => LandingPage::query()->count()));
    }

    public function test_refresh_refuses_a_published_page_and_changes_nothing(): void
    {
        $page = $this->ludo();
        $this->in(fn () => $page->update(['status' => 'published', 'published_at' => now(), 'blocks' => [['type' => 'text', 'props' => ['html' => '<p>terbit</p>']]], 'layout' => null]));
        $before = $page->refresh()->only(['blocks', 'layout']) + ['at' => (string) $page->updated_at];

        $this->assertSame(1, Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital', '--refresh' => true]));
        $this->assertStringContainsString('sudah terbit', Artisan::output());
        $this->assertSame($before, $page->refresh()->only(['blocks', 'layout']) + ['at' => (string) $page->updated_at]);
    }

    public function test_the_payment_choice_shows_qris_only_with_an_active_gateway(): void
    {
        $page = $this->ludo();
        $this->publish($page);
        $form = $this->in(fn () => LeadForm::query()->where('slug', SeedLudoPasutriCommand::FORM_SLUG)->firstOrFail());
        $send = fn (string $pay) => $this->postJson('/f/toko-digital/'.$form->slug, [
            'f' => ['nama' => 'Dina', 'whatsapp' => '089900001234', 'perangkat' => 'Android', 'pembayaran' => $pay], 'page' => $page->id, 'event_id' => 'ev-'.md5($pay),
        ]);

        $html = $this->get('/p/toko-digital/'.$page->slug)->getContent();
        $this->assertStringContainsString('Transfer bank', $html);
        $this->assertStringNotContainsString('QRIS / e-wallet', $html);
        $send('QRIS / e-wallet')->assertUnprocessable();

        $this->in(fn () => PaymentGateway::create(['va' => '0000005099000001', 'api_key' => 'SANDBOX-TEST-KEY-DIGITAL', 'sandbox' => true, 'active' => true, 'invoice_hours' => 24, 'access_days' => 7]));
        $html = $this->get('/p/toko-digital/'.$page->slug)->getContent();
        $this->assertStringContainsString('QRIS / e-wallet', $html);
        $send('QRIS / e-wallet')->assertOk();

        // Other agencies keep every choice they wrote.
        $shop = app(ProvisionTenant::class)('Toko Baju', 'toko-baju', User::factory()->create(), 'retail');
        $fields = [['key' => 'bayar', 'type' => 'radio', 'label' => 'Bayar', 'maps_to' => 'payment_type', 'payment_choices' => [['label' => 'COD', 'kind' => 'cod'], ['label' => 'QRIS', 'kind' => 'other']]]];
        $shopForm = $this->in(fn () => LeadForm::create(['name' => 'Order', 'slug' => 'order', 'fields' => $fields]), $shop);
        $this->assertSame(['COD', 'QRIS'], $shopForm->fieldList()[0]['options']);
    }

    public function test_the_signed_preview_is_dark_and_the_page_asks_nothing_from_other_sites(): void
    {
        $page = $this->ludo();
        $page->setRelation('tenant', $this->digital);
        $this->get('/p/toko-digital/'.$page->slug.'/pratinjau')->assertForbidden();

        $html = $this->get(LandingPageResource::previewUrl($page))->assertOk()->assertSee('Pratinjau')->getContent();
        $this->assertStringContainsString('--ac:#e9c27b', $html);

        $host = parse_url(url('/'), PHP_URL_HOST);
        preg_match_all('#\b(?:src|href|action)="(https?://[^"]+)"#', $html, $m);
        foreach ($m[1] as $url) {
            $this->assertTrue(parse_url($url, PHP_URL_HOST) === $host || str_starts_with($url, 'https://wa.me/'), "no request to another site: {$url}");
        }
        $this->assertDoesNotMatchRegularExpression('#@import|url\(\s*["\']?https?://#i', $html);
    }

    public function test_the_header_and_footer_name_the_agency_only(): void
    {
        $html = $this->publish($this->ludo());

        $this->assertStringContainsString('<span class="brand">Toko Digital</span>', $html);
        $this->assertStringContainsString('© '.now()->year.' Toko Digital', html_entity_decode($html));
        $this->assertStringNotContainsStringIgnoringCase('jitu', strip_tags($html));
    }

    public function test_the_editor_offers_the_theme_to_digital_agencies_and_saves_it(): void
    {
        $page = $this->ludo();
        $shop = app(ProvisionTenant::class)('Toko Baju', 'toko-baju', User::factory()->create(), 'retail');
        foreach ([$this->digital, $shop] as $t) {
            Artisan::call('plan:set', ['tenant' => $t->slug, 'plan' => 'agensi']);
            $t->unsetRelation('subscription');
        }

        $this->actingInTenant($this->adminOf($this->digital), $this->digital);
        Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])->assertOk()->assertSee('Gaya halaman')->assertSee('Kartu jenis')
            ->fillForm(['layout' => ['theme' => 'gelap', 'accent' => '#ff8800']])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['theme' => 'gelap', 'accent' => '#ff8800'], $page->refresh()->layout);
        $this->assertContains('kind_cards', array_column($page->blocks, 'type'), 'the sections survive a save');
        // The comparison points keep their stored shape (the editor used to open them one level too deep).
        $this->assertSame(['text' => 'Harus diunduh lalu dicetak'], collect($page->blocks)->firstWhere('type', 'comparison')['props']['left_items'][0]);

        $this->actingInTenant($this->adminOf($shop), $shop);
        $shopPage = $this->in(fn () => LandingPage::create(['title' => 'Promo', 'slug' => 'promo', 'status' => 'draft', 'color' => '#dc2626', 'blocks' => []]), $shop);
        Livewire::test(EditLandingPage::class, ['record' => $shopPage->getKey()])->assertOk()->assertDontSee('Gaya halaman')->assertDontSee('Kartu jenis')
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull($shopPage->refresh()->layout);
    }
}
