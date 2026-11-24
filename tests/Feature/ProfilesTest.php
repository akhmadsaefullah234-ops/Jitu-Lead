<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Agency\AgencyProfile;
use App\Enums\Role;
use App\Filament\Pages\AgencyProfilePage;
use App\Filament\Pages\Auth\MyProfile;
use App\LandingPages\Embeds;
use App\LandingPages\PageData;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\WhatsApp\TemplateRenderer;
use Filament\Auth\Notifications\VerifyEmailChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR E (24 Nov 2026): Profil saya for every role, Profil agensi for admins, and the agency profile as the one source
 * for landing pages ({logo, colour, WhatsApp, footer}) and templates ({wa_agensi}). Nothing changes while it is empty.
 */
class ProfilesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['password' => Hash::make('rahasia-lama-123')]);
        $this->shop = app(ProvisionTenant::class)('Toko Satu', 'toko-satu', $this->admin, 'retail');
    }

    private function agent(Tenant $tenant): User
    {
        $agent = User::factory()->create(['password' => Hash::make('sandi-agen-123')]);
        $tenant->users()->attach($agent, ['role' => 'agent', 'status' => 'active']);

        return $agent;
    }

    // ---- Profil saya ---------------------------------------------------------------------------------------------

    public function test_every_role_edits_name_whatsapp_and_photo_but_never_its_role(): void
    {
        $agent = $this->agent($this->shop);
        $this->actingInTenant($agent, $this->shop);

        Livewire::test(MyProfile::class)
            ->fillForm(['name' => 'Agen Baru', 'whatsapp' => '0899 0001 2345', 'avatar_path' => [UploadedFile::fake()->image('foto.jpg', 300, 300)], 'role' => 'admin'])
            ->call('save')->assertHasNoFormErrors();

        $agent->refresh();
        $this->assertSame(['Agen Baru', '6289900012345'], [$agent->name, $agent->whatsapp]);
        $this->assertStringStartsWith('avatars/'.$agent->id.'/', $agent->avatar_path);
        Storage::disk('public')->assertExists($agent->avatar_path);
        $this->assertNotNull($agent->getFilamentAvatarUrl());
        $this->assertSame(Role::Agent, $this->shop->users()->whereKey($agent->id)->first()->pivot->role);
    }

    public function test_the_photo_must_be_a_small_image_and_the_number_valid(): void
    {
        $this->actingInTenant($this->admin, $this->shop);

        Livewire::test(MyProfile::class)->fillForm(['avatar_path' => [UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')]])
            ->call('save')->assertHasFormErrors(['avatar_path']);
        Livewire::test(MyProfile::class)->fillForm(['avatar_path' => [UploadedFile::fake()->image('besar.jpg')->size(MyProfile::AVATAR_MAX_KB + 1)]])
            ->call('save')->assertHasFormErrors(['avatar_path']);
        Livewire::test(MyProfile::class)->fillForm(['whatsapp' => 'bukan nomor'])->call('save')->assertHasFormErrors(['whatsapp']);
    }

    public function test_a_new_password_needs_the_old_one_and_a_new_email_is_confirmed_first(): void
    {
        Notification::fake();
        $this->actingInTenant($this->admin, $this->shop);
        $old = $this->admin->email;

        Livewire::test(MyProfile::class)->fillForm(['password' => 'sandi-baru-456', 'passwordConfirmation' => 'sandi-baru-456', 'currentPassword' => 'salah'])
            ->call('save')->assertHasFormErrors(['currentPassword']);
        $this->assertTrue(Hash::check('rahasia-lama-123', $this->admin->fresh()->password));

        Livewire::test(MyProfile::class)->fillForm(['password' => 'sandi-baru-456', 'passwordConfirmation' => 'sandi-baru-456', 'currentPassword' => 'rahasia-lama-123'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('sandi-baru-456', $this->admin->fresh()->password));

        Livewire::test(MyProfile::class)->fillForm(['email' => 'baru@contoh.com', 'currentPassword' => 'sandi-baru-456'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($old, $this->admin->fresh()->email, 'The old address stays until the link is opened.');
        Notification::assertSentOnDemand(VerifyEmailChange::class);
    }

    public function test_logging_out_other_devices_needs_the_password(): void
    {
        $this->actingInTenant($this->admin, $this->shop);
        $hash = $this->admin->password;

        Livewire::test(MyProfile::class)->callAction('logoutOthers', ['password' => 'salah'])->assertHasActionErrors();
        $this->assertSame($hash, $this->admin->fresh()->password);

        Livewire::test(MyProfile::class)->callAction('logoutOthers', ['password' => 'rahasia-lama-123'])->assertHasNoActionErrors();
        $this->assertNotSame($hash, $this->admin->fresh()->password, 'The hash is renewed, so every other session is logged out.');
        $this->assertTrue(Hash::check('rahasia-lama-123', $this->admin->fresh()->password));
        $this->assertTrue(Auth::check());
    }

    // ---- Profil agensi -------------------------------------------------------------------------------------------

    private function fillAgency(array $data): void
    {
        Livewire::test(AgencyProfilePage::class)->fillForm($data)->call('save')->assertHasNoFormErrors();
    }

    public function test_an_admin_saves_the_agency_profile_and_the_slug_never_changes(): void
    {
        $this->actingInTenant($this->admin, $this->shop);

        $this->fillAgency([
            'name' => 'Toko Satu Sport', 'slug' => 'diganti', 'timezone' => 'Asia/Makassar', 'logo' => [UploadedFile::fake()->image('logo.png', 400, 200)],
            'color' => '#1D4ED8', 'whatsapp' => '0899-0000-1111', 'email' => 'halo@contoh.com', 'address' => 'Jl. Contoh No. 1, Bandung',
            'about' => 'Toko perlengkapan olahraga.', 'website' => 'https://contoh.com',
        ]);

        $tenant = $this->shop->fresh();
        $profile = AgencyProfile::for($tenant);
        $this->assertSame(['Toko Satu Sport', 'toko-satu', 'Asia/Makassar'], [$tenant->name, $tenant->slug, $tenant->timezone]);
        $this->assertSame(['#1d4ed8', '6289900001111', '089900001111', 'halo@contoh.com'],
            [$profile->color(), $profile->whatsapp(), $profile->whatsappText(), $profile->email()]);
        $this->assertStringStartsWith('landing/'.$tenant->id.'/', $profile->logoPath());
        Storage::disk('public')->assertExists($profile->logoPath());
        $this->assertSame(['Jl. Contoh No. 1, Bandung', 'Toko perlengkapan olahraga.', 'https://contoh.com'], [$profile->address(), $profile->about(), $profile->website()]);
    }

    public function test_the_logo_must_be_an_image_of_at_most_2_mb(): void
    {
        $this->actingInTenant($this->admin, $this->shop);

        Livewire::test(AgencyProfilePage::class)->fillForm(['logo' => [UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')]])->call('save')->assertHasFormErrors(['logo']);
        Livewire::test(AgencyProfilePage::class)->fillForm(['logo' => [UploadedFile::fake()->image('logo.png')->size(AgencyProfilePage::LOGO_MAX_KB + 1)]])->call('save')->assertHasFormErrors(['logo']);
        Livewire::test(AgencyProfilePage::class)->fillForm(['color' => 'merah', 'website' => 'bukan url'])->call('save')->assertHasFormErrors(['color', 'website']);
    }

    public function test_an_agent_cannot_open_the_agency_profile(): void
    {
        $this->actingInTenant($this->agent($this->shop), $this->shop);

        $this->assertFalse(AgencyProfilePage::canAccess());
        $this->get(AgencyProfilePage::getUrl(tenant: $this->shop))->assertForbidden();
    }

    public function test_each_agency_has_its_own_profile_and_cannot_use_another_agencys_logo(): void
    {
        $other = app(ProvisionTenant::class)('Griya Dua', 'griya-dua', User::factory()->create());
        $this->actingInTenant($this->admin, $this->shop);
        $this->fillAgency(['name' => 'Toko Satu', 'timezone' => 'Asia/Jakarta', 'whatsapp' => '089900001111', 'logo' => [UploadedFile::fake()->image('logo.png', 200, 100)]]);
        $logo = AgencyProfile::for($this->shop->fresh())->logoPath();

        $this->assertNull($other->fresh()->profile);
        $other->forceFill(['profile' => ['logo' => $logo, 'whatsapp' => '6289900002222']])->save();
        $this->assertNull(AgencyProfile::for($other->fresh())->logoPath(), 'Another agency\'s upload is never shown.');
        $this->assertNull(Embeds::image($logo, $other->id));
        $this->assertSame('6289900001111', AgencyProfile::for($this->shop->fresh())->whatsapp());
    }

    // ---- One source for pages and messages ---------------------------------------------------------------------

    public function test_landing_pages_and_templates_read_the_profile_and_are_unchanged_without_it(): void
    {
        $page = app(CurrentTenant::class)->run($this->shop, fn () => LandingPage::create(['title' => 'Promo', 'slug' => 'promo', 'status' => 'published', 'blocks' => []]));
        $lead = app(CurrentTenant::class)->run($this->shop, fn () => Lead::create(['name' => 'Rina', 'phone' => '+6289900003333', 'stage_id' => $this->shop->stages()->value('id') ?? null]));
        $render = fn () => app(TemplateRenderer::class)->render('Halo {nama}, WA {agensi}: {wa_agensi}', $lead->fresh(), $this->admin, $this->shop->name)['text'];

        $before = app(CurrentTenant::class)->run($this->shop, fn () => PageData::for($page->fresh(), $this->shop->fresh(), true));
        $this->assertNull($before['logo']);
        $this->assertSame('', $before['waNumber']);
        $this->assertSame('Halo Rina, WA Toko Satu: ', app(CurrentTenant::class)->run($this->shop, $render));

        $this->actingInTenant($this->admin, $this->shop);
        $this->fillAgency(['name' => 'Toko Satu', 'timezone' => 'Asia/Jakarta', 'whatsapp' => '089900001111', 'about' => 'Tentang toko kami.',
            'logo' => [UploadedFile::fake()->image('logo.png', 200, 100)]]);

        $after = app(CurrentTenant::class)->run($this->shop, fn () => PageData::for($page->fresh(), $this->shop->fresh(), true));
        $this->assertNotNull($after['logo']);
        $this->assertSame('6289900001111', $after['waNumber']);
        $this->assertSame('Halo Rina, WA Toko Satu: 089900001111', app(CurrentTenant::class)->run($this->shop, $render));

        // The page's own number and logo still win.
        $page->forceFill(['whatsapp_number' => '089900009999'])->save();
        $this->assertSame('6289900009999', app(CurrentTenant::class)->run($this->shop, fn () => PageData::for($page->fresh(), $this->shop->fresh(), true))['waNumber']);

        $this->get('/p/toko-satu/promo')->assertOk()->assertSee('Tentang toko kami.');
    }

    public function test_property_and_retail_agencies_without_a_profile_render_as_before(): void
    {
        $griya = $this->makeTenant('griya-tiga');
        $page = app(CurrentTenant::class)->run($griya, fn () => LandingPage::create(['title' => 'Rumah', 'slug' => 'rumah', 'status' => 'published', 'blocks' => []]));

        $html = $this->get('/p/griya-tiga/rumah')->assertOk()->getContent();
        $this->assertStringContainsString('&copy; '.now()->year.' Griya Tiga · ', $html);
        $this->assertNull(app(CurrentTenant::class)->run($griya, fn () => PageData::for($page->fresh(), $griya->fresh(), true))['logo']);
        $this->assertSame([], AgencyProfile::clean([]));
    }
}
