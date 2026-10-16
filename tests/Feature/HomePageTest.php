<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ContactSettings;
use App\Models\PlatformSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The public front page: prices and limits come from config/plans.php, testimonials from config/landing.php. */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_front_page_loads_with_the_main_sections_and_no_third_party_fonts(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Tidak ada lead yang terlewat')
            ->assertSee('Juga termasuk')->assertSee('Mulai menerima lead hari ini juga')->assertSee('Yang sering ditanyakan')
            ->assertSee('fonts.bunny.net', false)
            ->assertDontSee('fonts.googleapis.com', false)->assertDontSee('fonts.gstatic.com', false)
            ->assertDontSee('jitulead.com', false);
    }

    public function test_prices_limits_and_addons_come_from_the_plan_config(): void
    {
        config(['plans.plans.tim.price' => ['monthly' => 123456, 'yearly' => 1234560]]);
        config(['plans.plans.tim.limits.users' => 7]);
        config(['plans.addons.user.price' => 17000]);

        $html = $this->get('/')->assertOk()->getContent();

        foreach (config('plans.plans') as $plan) {
            $this->assertStringContainsString($plan['name'], $html);
            $this->assertStringContainsString('Rp '.number_format($plan['price']['monthly'], 0, ',', '.'), $html);
            $this->assertStringContainsString('data-y="'.number_format($plan['price']['yearly'], 0, ',', '.').'"', $html);
            $this->assertStringContainsString('setara Rp '.number_format(round($plan['price']['yearly'] / 12), 0, ',', '.').' / bulan', $html);
        }
        $this->assertStringNotContainsString('Rp 199.000', $html);
        $this->assertStringContainsString('Rp 17.000', $html);
        $this->assertMatchesRegularExpression('/Pengguna<\/span><span>7<\/span>/', $html);
        $this->assertStringContainsString('tanpa batas', $html);
        // The "pay N months" claim is worked out from the prices, so it disappears when they stop agreeing.
        $this->assertStringContainsString('Bayar tahunan cukup 10 bulan', $this->get('/')->getContent());
        config(['plans.plans.mandiri.price' => ['monthly' => 100000, 'yearly' => 900000]]);
        $this->assertStringNotContainsString('Bayar tahunan cukup', $this->get('/')->getContent());
    }

    public function test_the_trial_length_and_plan_follow_the_config(): void
    {
        config(['plans.trial_days' => 21]);

        $this->get('/')->assertSee('Coba gratis 21 hari')->assertDontSee('Coba gratis 14 hari');
    }

    public function test_the_testimonial_section_is_hidden_until_there_are_real_testimonials(): void
    {
        config(['landing.testimonials' => []]);

        $this->get('/')->assertOk()
            ->assertDontSee('id="testimoni"', false)->assertDontSee('href="#testimoni"', false)
            ->assertDontSee('Contoh tampilan')->assertDontSee('[Nama');
    }

    public function test_testimonials_from_the_config_are_shown_with_a_nav_link(): void
    {
        config(['landing.testimonials' => [
            ['quote' => 'Balasan jadi jauh lebih cepat.', 'name' => 'Budi Santoso', 'role' => 'Griya Prima · Denpasar', 'result' => 'Waktu balas: 3 jam → 4 menit'],
            ['quote' => '', 'name' => 'Tanpa Isi'],
        ]]);

        $this->get('/')->assertOk()
            ->assertSee('id="testimoni"', false)->assertSee('href="#testimoni"', false)
            ->assertSee('Balasan jadi jauh lebih cepat.')->assertSee('Budi Santoso')->assertSee('Griya Prima · Denpasar')
            ->assertSee('Waktu balas: 3 jam')->assertSee('>BS<', false)
            ->assertDontSee('Tanpa Isi');
    }

    public function test_sign_up_buttons_follow_the_registration_mode(): void
    {
        config(['jitu.registration' => 'open']);
        $this->get('/')->assertSee('href="'.url('/app/register').'"', false)->assertSee('Coba gratis');

        config(['jitu.registration' => 'invite']);
        $this->get('/')->assertSee('Daftar dengan kode undangan');

        config(['jitu.registration' => 'closed']);
        $this->get('/')->assertDontSee(url('/app/register'), false)->assertSee('href="'.url('/app/login').'"', false);
    }

    public function test_the_whatsapp_link_shows_only_when_a_contact_is_set(): void
    {
        config(['jitu.billing_contact' => null]);
        $this->get('/')->assertDontSee('wa.me', false);

        config(['jitu.billing_contact' => '+62 812-3456-7890']);
        $this->get('/')->assertSee('https://wa.me/6281234567890', false)->assertSee('Tanya dulu lewat WhatsApp');
    }

    public function test_internal_links_use_the_app_address_and_legal_pages_are_linked(): void
    {
        $this->get('/')->assertSee('href="'.url('/kebijakan-privasi').'"', false)->assertSee('href="'.url('/syarat-layanan').'"', false)
            ->assertSee('href="'.url('/app/login').'"', false);
    }

    public function test_the_contact_section_shows_the_saved_business_contact(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="kontak"', false)
            ->assertSee('akhmadsaefullah234@gmail.com')
            ->assertSee('href="tel:+6282113345882"', false)->assertSee('082113345882')
            ->assertSee('Perum GCC2 Blok E13/21 Rt. 027/007 Kedungwaringin Kab. Bekasi')
            ->assertSee('href="#kontak"', false);
    }

    public function test_each_contact_field_can_be_hidden_and_the_section_goes_when_none_is_shown(): void
    {
        PlatformSetting::write('contact', ['email' => 'a@b.co', 'phone' => '0811', 'address' => 'Jl. Contoh 1', 'show_email' => true, 'show_phone' => false, 'show_address' => false]);

        $this->get('/')->assertOk()->assertSee('a@b.co')->assertDontSee('0811')->assertDontSee('Jl. Contoh 1');

        PlatformSetting::write('contact', ['email' => 'a@b.co', 'phone' => '0811', 'address' => 'Jl. Contoh 1', 'show_email' => false, 'show_phone' => false, 'show_address' => false]);

        $this->get('/')->assertOk()->assertDontSee('id="kontak"', false)->assertDontSee('href="#kontak"', false)->assertDontSee('a@b.co');
    }

    public function test_contact_text_is_escaped(): void
    {
        PlatformSetting::write('contact', ['email' => null, 'phone' => null, 'address' => '<script>alert(1)</script>', 'show_email' => true, 'show_phone' => true, 'show_address' => true]);

        $this->get('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_the_owner_edits_the_contact_in_admin_and_the_front_page_follows(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        Livewire::test(ContactSettings::class)
            ->assertFormSet(['email' => 'akhmadsaefullah234@gmail.com', 'show_address' => true])
            ->fillForm(['email' => 'halo@contoh.id', 'phone' => '+62 812-0000-1111', 'address' => 'Jl. Baru 9, Bekasi', 'show_phone' => false])
            ->call('save')->assertHasNoFormErrors();

        $this->get('/')->assertOk()->assertSee('halo@contoh.id')->assertSee('Jl. Baru 9, Bekasi')->assertDontSee('812-0000-1111');
    }

    public function test_only_super_admins_open_the_contact_page(): void
    {
        $this->get('/admin/contact')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create())->get('/admin/contact')->assertForbidden();
    }

    public function test_phone_links_get_the_country_code(): void
    {
        $show = ['email' => null, 'address' => null, 'show_email' => true, 'show_phone' => true, 'show_address' => true];

        foreach (['082113345882' => '6282113345882', '+62 812-0000-1111' => '6281200001111', '6281234567890' => '6281234567890'] as $typed => $digits) {
            PlatformSetting::write('contact', ['phone' => $typed] + $show);

            $this->assertSame($digits, PlatformSetting::publicContact()['phone_digits'], $typed);
        }
    }
}
