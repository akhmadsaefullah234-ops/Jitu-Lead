<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\LegalPages;
use App\LandingPages\RichText;
use App\Legal\LegalDocs;
use App\Models\LandingPage;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legal.contact' => 'privasi@example.com', 'legal.company' => 'PT Contoh', 'legal.address' => null]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $owner;
    }

    public function test_both_pages_are_public_and_marked_as_a_draft_until_reviewed(): void
    {
        foreach (['kebijakan-privasi', 'syarat-layanan'] as $slug) {
            $this->get("/$slug")->assertOk()->assertSee('belum ditinjau oleh ahli hukum');
        }
    }

    public function test_the_privacy_policy_covers_what_the_pdp_law_asks_for(): void
    {
        $page = $this->get('/kebijakan-privasi')->assertOk();

        foreach (['UU PDP', 'Pengendali Data Pribadi', 'Prosesor Data Pribadi', 'nomor telepon', 'percakapan WhatsApp', 'Berapa lama data disimpan',
            'Hak Anda', 'dihapus atau dimusnahkan', 'memperoleh salinan', 'Anthropic', 'di luar Indonesia', 'privasi@example.com'] as $needle) {
            $page->assertSee($needle);
        }
    }

    public function test_placeholders_use_the_real_company_contact_and_retention_numbers(): void
    {
        config(['jitu.backup.keep_days' => 7, 'jitu.backup.remote_keep_days' => 30, 'legal.address' => 'Jl. Contoh 1, Jakarta']);

        $this->get('/kebijakan-privasi')->assertSee('PT Contoh')->assertSee('Jl. Contoh 1, Jakarta')->assertSee('7 hari di server')->assertSee('30 hari di penyimpanan luar server')
            ->assertDontSee('{kontak}', false)->assertDontSee('{backup_hari}', false);
    }

    public function test_the_terms_cover_trial_whatsapp_risk_and_ai(): void
    {
        $this->get('/syarat-layanan')->assertOk()->assertSee('14 hari')->assertSee('hanya-baca')->assertSee('diblokir')->assertSee('draf balasan dan bisa keliru');
    }

    public function test_unknown_slugs_are_not_pages(): void
    {
        $this->get('/lain-lain')->assertNotFound();
        $this->assertTrue(LegalDocs::exists('syarat-layanan'));
        $this->assertFalse(LegalDocs::exists('x'));
    }

    public function test_they_are_linked_from_the_front_page_the_sign_up_page_and_public_landing_pages(): void
    {
        config(['jitu.registration' => 'open']);

        $this->get('/')->assertSee('/kebijakan-privasi', false)->assertSee('/syarat-layanan', false);
        $this->get('/app/register')->assertOk()->assertSee('/syarat-layanan', false)->assertSee('/kebijakan-privasi', false);
        $this->get('/app/login')->assertOk()->assertSee('/kebijakan-privasi', false);
    }

    public function test_a_published_landing_page_links_to_them(): void
    {
        $tenant = $this->makeTenant();
        $slug = app(CurrentTenant::class)->run($tenant, fn () => LandingPage::create([
            'title' => 'Cluster', 'slug' => 'cluster', 'status' => 'published', 'blocks' => [], 'published_at' => now(),
        ])->slug);

        $this->get("/p/{$tenant->slug}/$slug")->assertOk()->assertSee('/kebijakan-privasi', false)->assertSee('/syarat-layanan', false);
    }

    public function test_the_owner_edits_and_marks_reviewed_and_the_draft_banner_goes(): void
    {
        $this->owner();

        Livewire::test(LegalPages::class)
            ->fillForm(['kebijakan-privasi.title' => 'Privasi Kami', 'kebijakan-privasi.body' => '<h2>Bagian</h2><p>Teks <strong>baru</strong> dari {perusahaan}.</p>', 'kebijakan-privasi.reviewed' => true])
            ->call('save')->assertHasNoFormErrors();

        $row = LegalDocument::where('slug', 'kebijakan-privasi')->first();
        $this->assertNotNull($row->reviewed_at);

        $this->get('/kebijakan-privasi')->assertSee('Privasi Kami')->assertSee('<h2>Bagian</h2>', false)->assertSee('Teks <strong>baru</strong> dari PT Contoh', false)
            ->assertDontSee('belum ditinjau oleh ahli hukum');
        $this->get('/syarat-layanan')->assertSee('belum ditinjau oleh ahli hukum');
    }

    public function test_saving_without_changes_keeps_the_built_in_text(): void
    {
        $this->owner();

        Livewire::test(LegalPages::class)->call('save');

        $this->assertSame(0, LegalDocument::count());
    }

    public function test_marking_reviewed_without_editing_keeps_the_built_in_text_flowing(): void
    {
        $this->owner();

        Livewire::test(LegalPages::class)->fillForm(['syarat-layanan.reviewed' => true])->call('save');

        $row = LegalDocument::where('slug', 'syarat-layanan')->first();
        $this->assertNull($row->body);
        $this->get('/syarat-layanan')->assertSee('Uji coba dan langganan')->assertDontSee('belum ditinjau oleh ahli hukum');
    }

    public function test_edited_text_is_sanitised_before_it_reaches_the_public(): void
    {
        $this->owner();
        LegalDocument::create(['slug' => 'syarat-layanan', 'body' => '<h2>Aman</h2><script>alert(1)</script><p onclick="x()">Teks <a href="javascript:alert(2)">tautan</a> <img src=x onerror=alert(3)></p>']);

        $html = $this->get('/syarat-layanan')->assertOk()->getContent();

        $this->assertStringContainsString('<h2>Aman</h2>', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_reset_returns_to_the_built_in_text(): void
    {
        $this->owner();
        LegalDocument::create(['slug' => 'syarat-layanan', 'body' => '<p>Kustom</p>']);

        Livewire::test(LegalPages::class)->callAction('reset');

        $this->assertSame(0, LegalDocument::count());
        $this->get('/syarat-layanan')->assertDontSee('Kustom');
    }

    public function test_only_the_owner_reaches_the_editor(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/legal-pages')->assertForbidden();
    }

    public function test_headings_are_kept_only_where_the_caller_allows_them(): void
    {
        $this->assertSame('<p><strong>Judul</strong></p>', RichText::clean('<h2>Judul</h2>'));
        $this->assertSame('<h2>Judul</h2>', RichText::clean('<h2>Judul</h2>', headings: true));
    }
}
