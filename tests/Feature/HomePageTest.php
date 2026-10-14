<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The public front page: prices and limits come from config/plans.php, testimonials from config/landing.php. */
class HomePageTest extends TestCase
{
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
}
