<?php

namespace Tests\Feature;

use App\Filament\Resources\LandingPages\Pages\EditLandingPage;
use App\LandingPages\BlockFormat;
use App\LandingPages\PageLayout;
use App\Models\LandingPage;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Space between landing page sections: page-wide on PC and HP, per section (padding and margin), and the quick buttons. */
class LandingSpacingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->tenant->regenerateCaptureToken();
    }

    private function page(array $blocks, ?array $layout = null): LandingPage
    {
        return app(CurrentTenant::class)->run($this->tenant, fn () => LandingPage::create([
            'title' => 'Uji', 'slug' => 'uji', 'status' => 'published', 'color' => '#dc2626', 'layout' => $layout,
            'blocks' => BlockFormat::normalize($blocks),
        ]));
    }

    private function b(string $type, array $props): array
    {
        return ['type' => $type, 'version' => BlockFormat::VERSION, 'props' => $props];
    }

    private function html(): string
    {
        return $this->get('/p/'.$this->tenant->slug.'/uji')->assertOk()->getContent();
    }

    private function blocks(): array
    {
        return [
            $this->b('hero', ['headline' => 'Rumah baru']),
            $this->b('text', ['heading' => 'Satu', 'html' => '<p>a</p>']),
            $this->b('text', ['heading' => 'Dua', 'html' => '<p>b</p>']),
        ];
    }

    public function test_a_page_without_spacing_settings_renders_exactly_as_before(): void
    {
        $this->page($this->blocks());
        $html = $this->html();

        $this->assertStringNotContainsString('section.blk{padding', $html);
        $this->assertStringNotContainsString('id="bx-', $html);
        $this->assertStringContainsString('section.blk { padding: 3rem 0; }', $html, 'the old default spacing stays');
        $this->assertSame('', PageLayout::css(null));
        $this->assertSame('', PageLayout::css(['spacing' => 'nonsense', 'spacing_mobile' => 'custom']));
    }

    public function test_page_spacing_presets_and_numbers_apply_to_every_section_with_separate_pc_and_hp_values(): void
    {
        $this->page($this->blocks(), ['spacing' => 'tight', 'spacing_mobile' => 'custom', 'spacing_mobile_px' => 12]);
        $html = $this->html();

        $this->assertStringContainsString('section.blk{padding-top:24px;padding-bottom:24px}', $html);
        $this->assertStringContainsString('@media (max-width:767px){section.blk{padding-top:12px;padding-bottom:12px}}', $html);

        $this->assertSame(80, PageLayout::spacing(['spacing' => 'loose']));
        $this->assertSame(48, PageLayout::spacing(['spacing' => 'normal']));
        $this->assertSame(PageLayout::MAX_PX, PageLayout::spacing(['spacing_mobile' => 'custom', 'spacing_mobile_px' => 9999], true));
        $this->assertNull(PageLayout::spacing(['spacing' => 'loose'], true), 'HP keeps the default unless set');
    }

    public function test_a_section_overrides_the_page_spacing_with_its_own_padding_and_margin_and_hp_overrides_pc(): void
    {
        $blocks = $this->blocks();
        $blocks[2]['props']['style'] = [
            'pad_top' => 0, 'pad_bottom' => 64, 'margin_top' => -40, 'margin_bottom' => 500,
            'mobile' => ['pad_bottom' => 8, 'margin_top' => 0],
        ];
        $this->page($blocks, ['spacing' => 'loose']);
        $html = $this->html();

        $this->assertStringContainsString('section.blk{padding-top:80px;padding-bottom:80px}', $html);
        $this->assertStringContainsString('#bx-3>section:not(.hero),#bx-3 .hero>.wrap{padding-top:0px}', $html);
        $this->assertStringContainsString('#bx-3>section:not(.hero),#bx-3 .hero>.wrap{padding-bottom:64px}', $html);
        $this->assertStringContainsString('#bx-3{margin-top:-40px;position:relative;z-index:2}', $html, 'a negative margin overlaps the section above');
        $this->assertStringContainsString('#bx-3{margin-bottom:120px}', $html, 'margins are clamped');
        $this->assertMatchesRegularExpression('/@media \(max-width:767px\)\{[^@]*#bx-3>section:not\(\.hero\),#bx-3 \.hero>\.wrap\{padding-bottom:8px\}[^@]*#bx-3\{margin-top:0px\}/', $html);
        $this->assertStringNotContainsString('id="bx-2"', $html, 'other sections keep the page spacing');
    }

    public function test_the_editor_saves_page_spacing_and_the_quick_buttons_remove_space_above_or_below_a_section(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $page = $this->page($this->blocks());

        $lw = Livewire::test(EditLandingPage::class, ['record' => $page->getKey()]);
        $lw->set('data.layout.spacing', 'custom')->set('data.layout.spacing_px', 30)->set('data.layout.spacing_mobile', 'tight')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['spacing' => 'custom', 'spacing_px' => 30, 'spacing_mobile' => 'tight'], $page->fresh()->layout);

        $keys = array_keys($lw->get('data.blocks'));
        $lw->set("data.blocks.{$keys[1]}.data.style.margin_top", 16)
            ->callAction(TestAction::make('clearTop')->schemaComponent('blocks')->arguments(['item' => $keys[1]]))
            ->callAction(TestAction::make('clearBottom')->schemaComponent('blocks')->arguments(['item' => $keys[0]]))
            ->call('save')->assertHasNoFormErrors();

        $blocks = $page->fresh()->blocks;
        $this->assertSame(0, $blocks[1]['props']['style']['pad_top']);
        $this->assertSame(0, $blocks[1]['props']['style']['mobile']['pad_top']);
        $this->assertNull($blocks[1]['props']['style']['margin_top'] ?? null, 'the margin on that side goes too');
        $this->assertSame(0, $blocks[0]['props']['style']['pad_bottom']);

        $html = $this->html();
        $this->assertStringContainsString('#bx-2>section:not(.hero),#bx-2 .hero>.wrap{padding-top:0px}', $html);
        $this->assertStringContainsString('#bx-1>section:not(.hero),#bx-1 .hero>.wrap{padding-bottom:0px}', $html, 'the hero can sit right on the next section');
        $this->assertStringContainsString('section.blk{padding-top:30px;padding-bottom:30px}', $html);
    }
}
