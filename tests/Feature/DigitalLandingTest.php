<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Console\Commands\SeedLudoPasutriCommand;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\LandingPages\BlockFormat;
use App\LandingPages\Sections;
use App\LandingPages\Templates;
use App\LeadForms\FormTemplates;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The "Produk digital" landing template, its order form, and the Ludo Pasutri draft (landing:ludo-pasutri). */
class DigitalLandingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $digital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->digital = app(ProvisionTenant::class)('Toko Digital', 'toko-digital', User::factory()->create(), 'digital');
    }

    private function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->digital, $fn);
    }

    public function test_the_template_has_valid_blocks_and_only_digital_agencies_see_it(): void
    {
        $blocks = Templates::blocks(Templates::DIGITAL);

        $this->assertSame(['hero', 'comparison', 'kind_cards', 'phone_shots', 'steps', 'benefits', 'bonus', 'tiers', 'faq', 'buy_bar', 'form'], array_column($blocks, 'type'));
        $this->assertSame($blocks, BlockFormat::normalize($blocks), 'every block survives the stored format');
        $this->assertCount(4, $blocks[4]['props']['items']);
        $this->assertSame('Sudah termasuk di harga {harga}', $blocks[6]['props']['badge']);
        $this->assertSame('product', $blocks[0]['props']['visual']);
        $this->assertSame([], $blocks[3]['props']['items'], 'the phone screenshots start empty');
        $this->assertTrue(Templates::sellsProduct(Templates::DIGITAL));

        $this->assertArrayHasKey(Templates::DIGITAL, Templates::options('digital'));
        $this->assertArrayNotHasKey(Templates::DIGITAL, Templates::options('retail'));
        $this->assertArrayNotHasKey(Templates::DIGITAL, Templates::options('properti'));

        foreach (['comparison', 'steps', 'bonus', 'tiers', 'buy_bar', 'kind_cards', 'phone_shots'] as $type) {
            $this->assertArrayHasKey($type, Sections::types('digital'));
            $this->assertArrayNotHasKey($type, Sections::types('retail'), 'shops and property agencies get no new sections');
            $this->assertArrayNotHasKey($type, Sections::types('properti'));
        }
    }

    public function test_a_page_from_the_template_takes_the_product_and_makes_the_digital_order_form(): void
    {
        $data = $this->in(function () {
            $product = Property::create(['name' => 'Kelas Excel', 'price_from' => 99000, 'compare_price' => 149000]);

            return CreateLandingPage::fromTemplate(['template' => Templates::DIGITAL, 'title' => 'Kelas Excel', 'slug' => 'kelas-excel', 'product_id' => $product->id, 'order_form' => 'new']);
        });

        $bar = collect($data['blocks'])->firstWhere('type', 'buy_bar')['props'];
        $this->assertSame(['Kelas Excel', 99000, 149000], [$bar['name'], $bar['price'], $bar['compare_price']]);
        $this->assertStringContainsString('Kelas Excel', collect($data['blocks'])->firstWhere('type', 'hero')['props']['headline']);
        $this->assertSame('draft', $data['status']);

        $form = $this->in(fn () => LeadForm::query()->findOrFail(collect($data['blocks'])->firstWhere('type', 'form')['props']['form_id']));
        $this->assertSame(FormTemplates::ORDER_DIGITAL, $form->template);
        $keys = array_column($form->fields, 'key');
        $this->assertSame(['nama', 'whatsapp', 'email', 'perangkat', 'pembayaran', 'utm_source', 'utm_campaign'], $keys);
        $this->assertSame(['Android', 'iPhone', 'Komputer'], collect($form->fields)->firstWhere('key', 'perangkat')['options']);
        $this->assertFalse((bool) collect($form->fields)->firstWhere('key', 'email')['required']);
        $this->assertNotContains('address', array_column($form->fields, 'type'));
    }

    public function test_the_order_form_makes_a_lead_in_lead_baru_with_utm_and_ad_cookies(): void
    {
        Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital']);
        $page = $this->in(fn () => LandingPage::query()->where('slug', SeedLudoPasutriCommand::PAGE_SLUG)->firstOrFail());
        $this->in(fn () => $page->update(['status' => 'published', 'published_at' => now()]));
        $form = $this->in(fn () => LeadForm::query()->where('slug', SeedLudoPasutriCommand::FORM_SLUG)->firstOrFail());

        $this->postJson('/f/toko-digital/'.$form->slug, [
            'f' => ['nama' => 'Dina', 'whatsapp' => '089900001234', 'perangkat' => 'Android', 'pembayaran' => 'Transfer bank'],
            'page' => $page->id, 'event_id' => 'ev-1', 'utm_source' => 'meta', 'utm_campaign' => 'ludo-november',
            'fbc' => 'fb.1.1700000000.AbC', 'fbp' => 'fb.1.1700000000.123',
        ])->assertOk();

        $lead = $this->in(fn () => Lead::query()->with('stage')->firstOrFail());
        $this->assertSame('Lead baru', $lead->stage->name);
        $this->assertSame(SeedLudoPasutriCommand::PRODUCT, $this->in(fn () => Property::query()->find($lead->property_id)?->name));
        $this->assertSame('transfer', $lead->payment_type);
        $this->assertSame('meta', $lead->custom_fields['kampanye']['utm_source'] ?? null);
        $this->assertSame('ludo-november', $lead->custom_fields['kampanye']['utm_campaign'] ?? null);
        $this->assertEqualsCanonicalizing(['fbc' => 'fb.1.1700000000.AbC', 'fbp' => 'fb.1.1700000000.123'], $lead->custom_fields['pelacakan'] ?? null);
    }

    public function test_the_ludo_command_is_idempotent_makes_a_draft_and_touches_no_other_agency(): void
    {
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'digital');
        $this->in(fn () => Property::create(['name' => 'Produk lain', 'price_from' => 1000]), $other);

        $this->assertSame(0, Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital']));
        $page = $this->in(fn () => LandingPage::query()->where('slug', SeedLudoPasutriCommand::PAGE_SLUG)->firstOrFail());
        $this->in(fn () => $page->update(['title' => 'Judul saya']));
        $this->assertSame(0, Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital']));

        $this->assertSame(1, $this->in(fn () => Property::query()->where('name', SeedLudoPasutriCommand::PRODUCT)->count()));
        $this->assertSame(99000, (int) $this->in(fn () => Property::query()->where('name', SeedLudoPasutriCommand::PRODUCT)->value('price_from')));
        $this->assertSame(1, $this->in(fn () => LeadForm::query()->where('slug', SeedLudoPasutriCommand::FORM_SLUG)->count()));
        $this->assertSame(1, $this->in(fn () => LandingPage::query()->count()));
        $page->refresh();
        $this->assertSame('draft', $page->status, 'never published by the command');
        $this->assertSame('Judul saya', $page->title, 'a second run keeps edits');

        $this->assertSame(0, $this->in(fn () => LandingPage::query()->count(), $other));
        $this->assertSame(['Produk lain'], $this->in(fn () => Property::query()->pluck('name')->all(), $other));
        $this->assertSame(0, $this->in(fn () => LeadForm::query()->where('slug', SeedLudoPasutriCommand::FORM_SLUG)->count(), $other));
    }

    public function test_the_command_refuses_a_missing_or_non_digital_agency(): void
    {
        $property = app(ProvisionTenant::class)('Rumah Contoh', 'rumah-contoh', User::factory()->create(), 'properti');

        $this->assertSame(1, Artisan::call('landing:ludo-pasutri', ['--tenant' => 'rumah-contoh']));
        $this->assertSame(1, Artisan::call('landing:ludo-pasutri', ['--tenant' => 'tidak-ada']));
        $this->assertSame(1, Artisan::call('landing:ludo-pasutri'));
        $this->assertSame(0, $this->in(fn () => Property::query()->count(), $property));
        $this->assertSame(0, $this->in(fn () => LandingPage::query()->count(), $property));
    }

    public function test_the_draft_is_not_public_and_the_published_page_is_light_and_neutral(): void
    {
        Artisan::call('landing:ludo-pasutri', ['--tenant' => 'toko-digital']);
        $page = $this->in(fn () => LandingPage::query()->where('slug', SeedLudoPasutriCommand::PAGE_SLUG)->firstOrFail());

        $this->get('/p/toko-digital/'.SeedLudoPasutriCommand::PAGE_SLUG)->assertNotFound();

        $this->in(fn () => $page->update(['status' => 'published', 'published_at' => now()]));
        $html = $this->get('/p/toko-digital/'.SeedLudoPasutriCommand::PAGE_SLUG)->assertOk()
            ->assertSee('Aplikasi permainan pasutri. Bukan PDF, tanpa print.')
            ->assertSee('Sudah termasuk di harga Rp99.000')
            ->assertSee('Rp 99.000')
            ->assertSee('class="buybar"', false)
            ->assertSee('href="#daftar"', false)
            ->getContent();

        $this->assertStringNotContainsString('fonts.googleapis', $html);
        $this->assertStringNotContainsString('fonts.bunny', $html);
        // "APK" only ever appears to say it is not one.
        preg_match_all('/[^.]*\bAPK\b[^.]*/i', strip_tags($html), $m);
        foreach ($m[0] as $sentence) {
            $this->assertMatchesRegularExpression('/bukan\s+(?:sebuah\s+)?APK|ini APK\?/i', $sentence);
        }
    }
}
