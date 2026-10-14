<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Admin\Resources\Banners\Pages\ManageBanners;
use App\Models\Banner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardBannerTest extends TestCase
{
    use RefreshDatabase;

    private function banner(array $attrs = []): Banner
    {
        return Banner::create($attrs + ['title' => 'Promo Oktober', 'image_path' => 'banners/a.png', 'is_active' => true, 'sort_order' => 0]);
    }

    private function dashboardAs(Role $role): TestResponse
    {
        $tenant = $this->makeTenant();
        $user = $role === Role::Admin ? $this->adminOf($tenant) : $this->member($tenant, $role);

        return $this->actingAs($user)->get('/app/'.$tenant->slug);
    }

    public function test_dashboard_has_no_banner_strip_when_none_are_live(): void
    {
        $this->banner(['is_active' => false]);

        $this->dashboardAs(Role::Admin)->assertOk()->assertDontSee('jl-banner-track', false);
    }

    public function test_live_banners_slide_at_the_top_with_their_links_in_order(): void
    {
        $this->banner(['title' => 'Kedua', 'image_path' => 'banners/b.png', 'sort_order' => 2, 'link_url' => 'https://example.com/promo']);
        $this->banner(['title' => 'Pertama', 'image_path' => 'banners/a.png', 'sort_order' => 1]);

        $res = $this->dashboardAs(Role::Agent)->assertOk()->assertSee('jl-banner-track', false)
            ->assertSee('href="https://example.com/promo"', false)->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('Banner berikutnya');

        $res->assertSeeInOrder(['alt="Pertama"', 'alt="Kedua"'], false);
    }

    public function test_only_live_banners_are_shown(): void
    {
        $this->banner(['title' => 'Belum mulai', 'starts_at' => now()->addDay()]);
        $this->banner(['title' => 'Sudah selesai', 'ends_at' => now()->subDay()]);
        $this->banner(['title' => 'Mati', 'is_active' => false]);
        $this->banner(['title' => 'Sedang tayang', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $this->assertSame(['Sedang tayang'], Banner::live()->pluck('title')->all());
    }

    public function test_a_single_banner_has_no_arrows(): void
    {
        $this->banner();

        $this->dashboardAs(Role::Admin)->assertOk()->assertSee('jl-banner-track', false)->assertDontSee('Banner berikutnya');
    }

    public function test_super_admin_uploads_a_banner_and_files_are_cleaned_up_on_replace_and_delete(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        Livewire::test(ManageBanners::class)->callAction('create', [
            'title' => 'Diskon langganan',
            'image_path' => UploadedFile::fake()->image('b.png', 1500, 500),
            'link_url' => 'https://example.com/diskon',
            'is_active' => true,
            'sort_order' => 1,
        ])->assertHasNoActionErrors();

        $banner = Banner::first();
        $this->assertSame('https://example.com/diskon', $banner->link_url);
        Storage::disk('public')->assertExists($banner->image_path);
        $old = $banner->image_path;

        Storage::disk('public')->put('banners/new.png', 'x');
        $banner->update(['image_path' => 'banners/new.png']);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists('banners/new.png');

        Livewire::test(ManageBanners::class)->callTableAction('delete', $banner->fresh());
        $this->assertSame(0, Banner::count());
        Storage::disk('public')->assertMissing('banners/new.png');
    }

    public function test_banner_link_must_be_http_or_https(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        Livewire::test(ManageBanners::class)->callAction('create', [
            'title' => 'X', 'image_path' => UploadedFile::fake()->image('b.png'), 'link_url' => 'javascript:alert(1)', 'is_active' => true, 'sort_order' => 0,
        ])->assertHasActionErrors(['link_url']);
        $this->assertSame(0, Banner::count());
    }

    public function test_agencies_cannot_open_the_banner_settings(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->adminOf($tenant))->get('/admin/banners')->assertForbidden();
    }
}
