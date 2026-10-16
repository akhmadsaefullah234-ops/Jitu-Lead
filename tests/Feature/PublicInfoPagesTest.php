<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ContactSettings;
use App\Filament\Admin\Pages\LegalPages;
use App\Marketing\Faq;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\PhoneNumber;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicInfoPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'Perum GCC2 Blok E13/21 Rt. 027/007 Kedungwaringin Kab. Bekasi';

    private function owner(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);
    }

    public function test_the_four_public_pages_open_without_login(): void
    {
        foreach (['faq', 'kontak', 'kebijakan-pengembalian-dana', 'syarat-layanan'] as $path) {
            $this->get("/$path")->assertOk();
        }
    }

    public function test_the_contact_page_shows_company_address_email_phone_and_hours(): void
    {
        config(['legal.company' => 'PT Contoh Jaya']);
        PlatformSetting::write('contact', ['email' => 'halo@contoh.id', 'phone' => '082113345882', 'address' => self::ADDRESS, 'hours' => 'Senin-Jumat 09.00-17.00 WIB', 'address_display' => 'hidden', 'show_email' => false, 'show_phone' => false]);

        $this->get('/kontak')->assertOk()
            ->assertSee('PT Contoh Jaya')->assertSee(self::ADDRESS)->assertSee('halo@contoh.id')
            ->assertSee('082113345882')->assertSee('https://wa.me/6282113345882', false)
            ->assertSee('Senin-Jumat 09.00-17.00 WIB');
    }

    public function test_the_faq_comes_from_one_source_for_landing_and_faq_page(): void
    {
        $landing = $this->get('/')->assertOk();
        $faq = $this->get('/faq')->assertOk();

        $this->assertNotEmpty(Faq::items());

        foreach (Faq::items() as $item) {
            $landing->assertSee($item['q'], false);
            $faq->assertSee($item['q'], false);
        }
    }

    public function test_the_refund_policy_states_the_rules(): void
    {
        config(['plans.trial_days' => 14]);

        $this->get('/kebijakan-pengembalian-dana')->assertOk()
            ->assertSee('Kebijakan Pengembalian Dana')->assertSee('14 hari')->assertSee('ganda')
            ->assertSee('7 hari')->assertSee('7 sampai 14 hari kerja')->assertSee('bukti')
            ->assertSee('akhmadsaefullah234@gmail.com')->assertDontSee('{kontak}', false)->assertDontSee('{tautan', false);
    }

    public function test_the_terms_link_to_the_refund_policy(): void
    {
        $this->get('/syarat-layanan')->assertSee('href="'.url('/kebijakan-pengembalian-dana').'"', false);
    }

    public function test_the_owner_can_edit_the_refund_policy_in_admin(): void
    {
        $this->owner();

        Livewire::test(LegalPages::class)
            ->fillForm(['kebijakan-pengembalian-dana.body' => '<h2>Bagian</h2><p>Isi baru kebijakan</p>', 'kebijakan-pengembalian-dana.reviewed' => true])
            ->call('save')->assertHasNoFormErrors();

        $this->get('/kebijakan-pengembalian-dana')->assertSee('Isi baru kebijakan');
    }

    public function test_every_public_page_footer_links_to_all_info_pages(): void
    {
        foreach (['/', '/faq', '/kontak', '/harga', '/kebijakan-privasi', '/syarat-layanan', '/kebijakan-pengembalian-dana'] as $path) {
            $page = $this->get($path)->assertOk();

            foreach (['faq', 'kontak', 'kebijakan-privasi', 'syarat-layanan', 'kebijakan-pengembalian-dana'] as $slug) {
                $page->assertSee('href="'.url("/$slug").'"', false);
            }
        }
    }

    public function test_address_display_modes_on_landing_while_full_address_stays_on_contact_and_legal_pages(): void
    {
        $base = ['email' => 'a@b.co', 'phone' => '082113345882', 'address' => self::ADDRESS, 'city' => 'Bekasi'];

        PlatformSetting::write('contact', $base + ['address_display' => 'full']);
        $this->get('/')->assertSee(self::ADDRESS);

        PlatformSetting::write('contact', $base + ['address_display' => 'city']);
        $this->get('/')->assertDontSee(self::ADDRESS)->assertSee('Bekasi');

        PlatformSetting::write('contact', $base + ['address_display' => 'hidden']);
        $this->get('/')->assertDontSee(self::ADDRESS);

        $this->get('/kontak')->assertSee(self::ADDRESS);
        $this->get('/kebijakan-privasi')->assertSee(self::ADDRESS);
    }

    public function test_whatsapp_digits_are_normalized(): void
    {
        foreach (['082113345882' => '6282113345882', '+62 812-0000-1111' => '6281200001111', '6281234567890' => '6281234567890', '+62 0812 0000 1111' => '6281200001111'] as $typed => $digits) {
            $this->assertSame($digits, PhoneNumber::whatsapp($typed), $typed);
        }

        foreach (['abc', '12345', '021-5551234', ''] as $bad) {
            $this->assertNull(PhoneNumber::whatsapp($bad), $bad);
        }
    }

    public function test_the_billing_contact_link_uses_the_country_code(): void
    {
        config(['jitu.billing_contact' => '08123456789']);

        $this->get('/')->assertSee('href="https://wa.me/628123456789"', false);
    }

    public function test_admin_saves_contact_and_rejects_an_invalid_phone(): void
    {
        $this->owner();

        Livewire::test(ContactSettings::class)
            ->fillForm(['phone' => 'bukan nomor'])->call('save')->assertHasFormErrors(['phone']);

        Livewire::test(ContactSettings::class)
            ->fillForm(['phone' => '0812 0000 1111', 'hours' => 'Setiap hari 08.00-20.00', 'address_display' => 'city', 'city' => 'Bekasi'])
            ->call('save')->assertHasNoFormErrors();

        $this->get('/kontak')->assertSee('Setiap hari 08.00-20.00')->assertSee('https://wa.me/6281200001111', false);
    }
}
