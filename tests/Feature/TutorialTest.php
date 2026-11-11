<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Console\Commands\TutorialCaptureCommand;
use App\Enums\Role;
use App\Filament\Pages\Tutorial;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\MediaFolder;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\Industry;
use App\Tutorials\CaptureFakes;
use App\Tutorials\Chapter;
use App\Tutorials\Tutorials;
use Database\Seeders\AgensiDemoSeeder;
use Database\Seeders\TokoDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The in-app tutorial (docs/tutorial.md): chapters from resources/tutorials, filtered by kind of business and role,
 * with their pictures on disk, made-up data only, and demo seeders that refuse to run in production.
 */
class TutorialTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Tenant
    {
        return app(ProvisionTenant::class)('Toko Uji', 'toko-uji', User::factory()->create(), 'retail');
    }

    private function agency(): Tenant
    {
        return app(ProvisionTenant::class)('Agensi Uji', 'agensi-uji', User::factory()->create(), 'properti');
    }

    /** @return list<string> */
    private function visibleSlugs(Tenant $tenant, ?Role $role): array
    {
        return app(CurrentTenant::class)->run($tenant, fn () => Tutorials::visible(Industry::current(), $role)->keys()->all());
    }

    public function test_the_list_and_every_chapter_open_for_an_admin(): void
    {
        $shop = $this->shop();
        $this->actingInTenant($this->adminOf($shop), $shop);
        $url = '/app/'.$shop->slug.'/tutorial';

        $list = $this->get($url)->assertOk()->assertSee('Tutorial')->assertSee('Lead masuk')->assertSee('Percakapan dan AI');

        foreach (Tutorials::visible(Industry::current(), Role::Admin) as $chapter) {
            $list->assertSee($chapter->title);
            $this->get($url.'?bab='.$chapter->slug)->assertOk()->assertSee($chapter->title)
                ->assertSee('Menu:')->assertSee('tutorial/'.$chapter->slug.'/', false);
        }

        $this->get($url.'?bab=tidak-ada')->assertOk()->assertSee('Bab ini tidak ada');
    }

    public function test_the_shop_core_chapters_are_there(): void
    {
        foreach (['tim-dan-peran', 'dasbor', 'formulir-pemesanan', 'halaman-landing', 'uji-ab-landing', 'inbox', 'mode-ai', 'pengetahuan-ai', 'daftar-lead', 'pipeline', 'menu-pengiriman'] as $slug) {
            $this->assertArrayHasKey($slug, Tutorials::all(), $slug);
        }
    }

    public function test_the_remaining_shop_and_general_chapters_are_there(): void
    {
        $shop = ['produk', 'promo-ongkir', 'otomatisasi-tahap', 'menghubungkan-everpro', 'cek-ongkir', 'membuat-resi', 'resi-otomatis',
            'pesan-status-kiriman', 'paket-gagal-antar', 'hubungkan-impor-resi'];
        $general = ['pesan-sambutan', 'impor-lead', 'koneksi-whatsapp', 'pustaka-media', 'follow-up-otomatis', 'tugas', 'pelacakan-iklan',
            'label-whatsapp-business', 'laporan', 'laporan-harian', 'tahap-sumber-alasan', 'notifikasi', 'langganan', 'data-privasi'];

        foreach ($shop as $slug) {
            $this->assertSame(['retail'], Tutorials::all()[$slug]->industries, $slug);
        }

        foreach ($general as $slug) {
            $this->assertSame(array_keys(config('industries.industries')), Tutorials::all()[$slug]->industries, $slug);
        }

        // Pages an agent cannot open are not offered to an agent.
        foreach (['koneksi-whatsapp', 'follow-up-otomatis', 'otomatisasi-tahap', 'menghubungkan-everpro', 'pelacakan-iklan', 'tahap-sumber-alasan', 'langganan', 'data-privasi'] as $slug) {
            $this->assertTrue(Tutorials::all()[$slug]->admin, $slug);
        }

        $this->assertTrue(Tutorials::all()['produk']->leader);
        $this->assertNotContains('produk', $this->visibleSlugs($this->agency(), Role::Admin));
    }

    public function test_the_property_chapters_are_there_and_replace_the_shop_ones_on_the_same_page(): void
    {
        $property = ['katalog-properti', 'pipeline-properti', 'menggali-kebutuhan', 'jadwal-survei', 'booking-closing', 'gugur',
            'landing-properti', 'formulir-properti', 'follow-up-properti', 'dasbor-properti'];

        foreach ($property as $slug) {
            $this->assertSame(['properti'], Tutorials::all()[$slug]->industries, $slug);
        }

        // The shop versions of the board and the form are shop-only now; each property chapter takes their place.
        $this->assertSame(['retail'], Tutorials::all()['pipeline']->industries);
        $this->assertSame(['retail'], Tutorials::all()['formulir-pemesanan']->industries);

        $agency = $this->agency();
        $this->assertSame([], array_diff($property, $this->visibleSlugs($agency, Role::Admin)));
        $agent = $this->visibleSlugs($agency, Role::Agent);
        $this->assertContains('jadwal-survei', $agent);
        $this->assertNotContains('katalog-properti', $agent);
        $this->assertNotContains('follow-up-properti', $this->visibleSlugs($agency, Role::Manager));

        $this->actingInTenant($this->adminOf($agency), $agency);
        $base = '/app/'.$agency->slug;
        $this->get($base.'/pipeline')->assertOk()->assertSee('tutorial?bab=pipeline-properti', false);
        $this->get($base.'/lead-forms')->assertOk()->assertSee('tutorial?bab=formulir-properti', false);
        $this->get($base.'/properties')->assertOk()->assertSee('tutorial?bab=katalog-properti', false);
        $this->get($base.'/landing-pages')->assertOk()->assertSee('tutorial?bab=landing-properti', false);
        $this->get($base.'/lost-reasons')->assertOk()->assertSee('tutorial?bab=gugur', false);

        foreach ($property as $slug) {
            $this->get($base.'/tutorial?bab='.$slug)->assertOk()->assertSee(Tutorials::all()[$slug]->title)->assertSee('tutorial/'.$slug.'/', false);
        }
    }

    public function test_capture_fakes_answer_for_everpro_and_refuse_every_other_call(): void
    {
        $this->assertFalse(CaptureFakes::enabled());
        config(['jitu.tutorial_capture' => true]);
        $this->assertTrue(CaptureFakes::enabled());
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(CaptureFakes::enabled());
        $this->app->detectEnvironment(fn () => 'testing');

        CaptureFakes::register();
        $this->assertSame(750000, Http::get('https://client-api-sandbox.everpro.id/balance/v1/detail')->json('data.cash'));
        $rates = Http::post('https://client-api-sandbox.everpro.id/shipment/v2/rates', ['is_cod' => true])->json('data.regular');
        $this->assertNotEmpty($rates);
        $this->assertSame(503, Http::post('https://api.anthropic.com/v1/messages')->status());
        $this->assertSame(503, Http::get('https://graph.facebook.com/v19.0/me')->status());
    }

    public function test_a_shop_sees_no_property_chapter_and_an_agency_no_shop_chapter(): void
    {
        $shopSlugs = $this->visibleSlugs($this->shop(), Role::Admin);
        $agencySlugs = $this->visibleSlugs($this->agency(), Role::Admin);

        foreach (Tutorials::all() as $chapter) {
            $this->assertSame(in_array('retail', $chapter->industries, true), in_array($chapter->slug, $shopSlugs, true), $chapter->slug);
            $this->assertSame(in_array('properti', $chapter->industries, true), in_array($chapter->slug, $agencySlugs, true), $chapter->slug);
        }

        $this->assertContains('menu-pengiriman', $shopSlugs);
        $this->assertNotContains('menu-pengiriman', $agencySlugs);
        $this->assertContains('inbox', $agencySlugs);

        $property = Chapter::fromArray('coba-properti', ['title' => 'Jadwal survei', 'group' => 'properti', 'industries' => ['properti']]);
        $this->assertTrue($property->for(Industry::make('properti')));
        $this->assertFalse($property->for(Industry::make('retail')));
        $this->assertArrayNotHasKey('properti', Tutorials::groups(Industry::make('retail')));
        $this->assertArrayHasKey('properti', Tutorials::groups(Industry::make('properti')));
    }

    public function test_an_agent_does_not_see_admin_or_leader_chapters(): void
    {
        $shop = $this->shop();
        $agent = $this->member($shop, Role::Agent);
        $admin = $this->visibleSlugs($shop, Role::Admin);
        $leader = $this->visibleSlugs($shop, Role::Manager);
        $agentSlugs = $this->visibleSlugs($shop, Role::Agent);

        foreach (Tutorials::all() as $chapter) {
            if (! in_array($chapter->slug, $admin, true)) {
                continue;
            }

            $this->assertSame(! $chapter->admin, in_array($chapter->slug, $leader, true), $chapter->slug);
            $this->assertSame(! $chapter->admin && ! $chapter->leader, in_array($chapter->slug, $agentSlugs, true), $chapter->slug);
        }

        $this->assertNotContains('mode-ai', $agentSlugs);
        $this->assertNotContains('formulir-pemesanan', $agentSlugs);
        $this->assertContains('formulir-pemesanan', $leader);
        $this->assertContains('inbox', $agentSlugs);

        $this->actingInTenant($agent, $shop);
        $url = '/app/'.$shop->slug.'/tutorial';
        $this->get($url)->assertOk()->assertSee('Inbox')->assertDontSee('Siapa yang membalas')->assertDontSee('Mode AI</span>', false);
        $this->get($url.'?bab=mode-ai')->assertOk()->assertSee('Bab ini tidak ada');
    }

    public function test_search_finds_chapters_by_their_words(): void
    {
        $shop = $this->shop();
        $this->actingInTenant($this->adminOf($shop), $shop);

        $found = Tutorials::search('cetak label', Industry::current(), Role::Admin)->keys()->all();
        $this->assertContains('menu-pengiriman', $found);
        $this->assertNotContains('dasbor', $found);
        $this->assertSame([], Tutorials::search('kata-yang-tidak-ada-di-mana-pun', Industry::current(), Role::Admin)->keys()->all());

        $results = fn ($chapters) => $chapters->flatten()->map(fn (Chapter $c) => $c->slug)->all();
        Livewire::test(Tutorial::class)->set('search', 'uji a/b')->assertSee('Uji A/B halaman landing')
            ->assertViewHas('chapters', fn ($chapters) => $results($chapters) === ['uji-ab-landing'])
            ->set('search', 'zzzz tidak ada')->assertSee('Tidak ada bab yang cocok');
    }

    public function test_every_picture_a_chapter_names_is_on_disk(): void
    {
        $count = 0;

        foreach (Tutorials::all() as $chapter) {
            foreach ($chapter->steps as $i => $step) {
                foreach (array_filter([$step['image'], $step['mobile']]) as $image) {
                    $this->assertFileExists(public_path($image), $chapter->slug.' langkah '.($i + 1));
                    $this->assertNotSame('', trim($step['alt']), $chapter->slug.' langkah '.($i + 1).' tanpa teks alt');
                    $count++;
                }
            }
        }

        $this->assertGreaterThan(40, $count);
    }

    public function test_chapter_files_hold_no_real_phone_number_or_email(): void
    {
        $files = glob(resource_path('tutorials/*.php'));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $text = file_get_contents($file);
            preg_match_all('/(?:\+?62|0)8[\d\s-]{7,}/', $text, $phones);

            foreach ($phones[0] as $phone) {
                $digits = preg_replace('/\D/', '', $phone);
                $this->assertMatchesRegularExpression('/^(0|62)899000\d{4}$/', $digits, basename($file).': '.$phone);
            }

            preg_match_all('/[\w.+-]+@[\w-]+(\.[\w-]+)+/', $text, $emails);

            foreach ($emails[0] as $email) {
                $this->assertMatchesRegularExpression('/@(toko|agensi)-demo\.test$/', $email, basename($file).': '.$email);
            }
        }
    }

    public function test_chapter_text_is_cleaned_and_takes_the_agency_words(): void
    {
        $this->assertSame('<strong>Produk</strong> dan produk', strip_tags(Chapter::html('<script>alert(1)</script><b>{katalog}</b> dan {item}', Industry::make('retail')), '<strong>'));
        $this->assertStringNotContainsString('script', Chapter::html('<script>alert(1)</script>x', Industry::make('retail')));
        $this->assertStringContainsString('Properti', Chapter::html('{katalog}', Industry::make('properti')));

        foreach (Tutorials::all() as $chapter) {
            $this->assertArrayHasKey($chapter->group, Tutorials::GROUPS, $chapter->slug);

            foreach ($chapter->pages as $page) {
                $this->assertTrue(class_exists($page), $chapter->slug.': '.$page);
            }
        }
    }

    public function test_pages_link_to_their_chapter(): void
    {
        $shop = $this->shop();
        $this->actingInTenant($this->adminOf($shop), $shop);

        $this->get('/app/'.$shop->slug.'/inbox')->assertOk()->assertSee('Lihat tutorial')->assertSee('tutorial?bab=inbox', false);
        $this->get('/app/'.$shop->slug.'/pipeline')->assertOk()->assertSee('tutorial?bab=pipeline', false);
        $this->get('/app/'.$shop->slug.'/lead-forms')->assertOk()->assertSee('tutorial?bab=formulir-pemesanan', false);
    }

    public function test_demo_seeders_and_capture_refuse_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        foreach ([TokoDemoSeeder::class, AgensiDemoSeeder::class] as $seeder) {
            try {
                app($seeder)->run();
                $this->fail($seeder.' jalan di production');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('local atau testing', $e->getMessage());
            }
        }

        $this->assertFalse(Tenant::query()->whereIn('slug', ['toko-demo', 'agensi-demo'])->exists());
        $this->artisan(TutorialCaptureCommand::class)->assertFailed();
    }

    public function test_demo_seeders_build_the_two_demo_agencies(): void
    {
        $this->seed(TokoDemoSeeder::class);
        $this->seed(AgensiDemoSeeder::class);

        $toko = Tenant::query()->where('slug', 'toko-demo')->firstOrFail();
        $agensi = Tenant::query()->where('slug', 'agensi-demo')->firstOrFail();
        $this->assertSame('retail', $toko->industry()->key);
        $this->assertSame('properti', $agensi->industry()->key);

        app(CurrentTenant::class)->run($toko, function () {
            $this->assertSame(12, Lead::query()->count());
            $this->assertSame(3, Property::query()->count());
            $this->assertSame(5, Shipment::query()->count());
            $this->assertSame(['Foto produk', 'Price list', 'Testimoni', 'Lainnya'], MediaFolder::query()->orderBy('position')->pluck('name')->all());
            Lead::query()->pluck('phone')->each(fn ($p) => $this->assertMatchesRegularExpression('/^\+?(62|0)899000\d{4}$/', $p));
        });
        app(CurrentTenant::class)->run($agensi, function () {
            $this->assertSame(12, Lead::query()->count());
            $this->assertSame(3, Property::query()->count());
            $form = LeadForm::query()->where('slug', 'minat-cluster-contoh')->firstOrFail();
            $this->assertSame('properti', $form->template);
            $this->assertTrue($form->is_primary);
            $this->assertSame(['Booking', 'Closing', 'Gugur', 'Jadwal survei'], Lead::query()->with('stage')->get()
                ->pluck('stage.name')->intersect(['Booking', 'Closing', 'Gugur', 'Jadwal survei'])->unique()->sort()->values()->all());
        });

        // Seeding again replaces the demo agency instead of adding a second one.
        $this->seed(TokoDemoSeeder::class);
        $this->assertSame(1, Tenant::query()->where('slug', 'toko-demo')->count());
    }
}
