<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Filament\Resources\LandingPages\Pages\CreateLandingPage;
use App\Models\LandingPage;
use App\Models\Property;
use App\Models\User;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A property and a shop landing page render byte for byte as they did before the "Produk digital" look (Prompt I):
 * the fixtures were made from main before that change. Regenerate only on purpose: LANDING_SNAPSHOT_WRITE=1.
 */
class LandingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string}> industry, tenant slug, template */
    public static function pages(): array
    {
        return [
            'properti' => ['properti', 'griya-contoh', 'kpr'],
            'retail' => ['retail', 'toko-contoh', 'produk_promo'],
            'retail konversi' => ['retail', 'toko-konversi', 'konversi_produk'],
        ];
    }

    #[DataProvider('pages')]
    public function test_light_pages_render_exactly_as_before(string $industry, string $slug, string $template): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
        $tenant = app(ProvisionTenant::class)('Agensi Contoh', $slug, User::factory()->create(['email' => $slug.'@example.com']), $industry);

        app(CurrentTenant::class)->run($tenant, function () use ($template, $industry) {
            $product = $industry === 'retail' ? Property::create(['name' => 'Kaos Contoh', 'price_from' => 99000, 'compare_price' => 149000, 'details' => ['Warna' => 'Hitam, Putih', 'Bahan' => 'Katun']]) : null;
            $data = CreateLandingPage::fromTemplate(['template' => $template, 'title' => 'Halaman Contoh', 'slug' => 'contoh', 'product_id' => $product?->id, 'order_form' => 'new']);
            LandingPage::create(['status' => 'published', 'published_at' => now()] + $data);
        });

        $html = self::normalize($this->get('/p/'.$slug.'/contoh')->assertOk()->getContent());
        $file = base_path('tests/Fixtures/landing/'.$slug.'.html');

        if (getenv('LANDING_SNAPSHOT_WRITE')) {
            file_put_contents($file, $html);
        }

        $this->assertFileExists($file);
        $this->assertSame(file_get_contents($file), $html);
    }

    /** Ids that differ per run (database keys, tokens, the form's time stamp signed with APP_KEY) become placeholders. */
    public static function normalize(string $html): string
    {
        return (string) preg_replace([
            '/lf_hp_[A-Za-z0-9]+/', '/name="_token" value="[^"]*"/', '/"csrf-token" content="[^"]*"/', '/\/(f|p)\/([a-z-]+)\/[a-z0-9-]+\?[^"\']*/',
            '/(form_id|data-form|data-page|name="page" value)[="]*\d+/', '/ev-[A-Za-z0-9-]{8,}/',
            '/(?<=["\/=])\d{1,6}(?=\/)/', '/name="lf_ts" value="[^"]*"/',
        ], ['lf_hp_x', 'name="_token" value=""', '"csrf-token" content=""', '/$1/$2/x', '$1=N', 'ev-x', 'N', 'name="lf_ts" value=""'], $html);
    }
}
