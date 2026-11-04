<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Enums\Role;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Pipeline;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Widgets\Dashboard\AdPerformance;
use App\Filament\Widgets\Dashboard\AgentPerformance;
use App\Filament\Widgets\Dashboard\AutomationActivity;
use App\Filament\Widgets\Dashboard\CurrentStatus;
use App\Filament\Widgets\Dashboard\LeadFunnel;
use App\Filament\Widgets\Dashboard\PeriodStats;
use App\Filament\Widgets\Dashboard\ProductPerformance;
use App\Filament\Widgets\LeadsBySource;
use App\Models\Banner;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\DashboardData;
use App\Reports\LeadOrigin;
use App\Support\CurrentTenant;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class PeriodDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 4 November 2026, 23:45 WIB.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:45:00', 'Asia/Jakarta'));
        $this->admin = User::factory()->create();
        $this->shop = app(ProvisionTenant::class)('Toko Uji', 'toko-uji', $this->admin, 'retail');
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $fn);
    }

    private function retailStage(string $key): Stage
    {
        return $this->in($this->shop, fn () => app(RetailStages::class)->stage($key));
    }

    /** A lead that came in at $at (WIB wall time). */
    private function leadAt(string $at, array $attributes = [], ?Tenant $tenant = null): Lead
    {
        $tenant ??= $this->shop;
        $stage = Stage::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->orderBy('position')->first();
        $owner = $tenant->users()->wherePivot('role', Role::Admin->value)->firstOrFail();
        $lead = $this->in($tenant, fn () => Lead::create($attributes + [
            'name' => 'Calon Pembeli', 'phone' => '0812'.random_int(10000000, 99999999),
            'stage_id' => $stage->getKey(), 'owner_id' => $owner->getKey(),
        ]));
        $lead->forceFill(['created_at' => CarbonImmutable::parse($at, 'Asia/Jakarta')])->saveQuietly();

        return $lead;
    }

    /** Moves the lead into $stage at $at, the way MoveLeadToStage records it. */
    private function moveAt(Lead $lead, Stage $stage, string $at): void
    {
        $when = CarbonImmutable::parse($at, 'Asia/Jakarta');
        $from = $lead->stage_id;
        $lead->forceFill(['stage_id' => $stage->getKey(), 'stage_entered_at' => $when])->saveQuietly();
        $this->in(Tenant::find($lead->tenant_id), function () use ($lead, $from, $stage, $when) {
            $activity = $lead->activities()->create(['type' => 'stage_changed', 'body' => 'Pindah', 'meta' => ['from' => $from, 'to' => $stage->getKey()]]);
            $activity->forceFill(['created_at' => $when])->saveQuietly();
        });
    }

    private function product(string $name, int $price, ?Tenant $tenant = null): Property
    {
        return $this->in($tenant ?? $this->shop, fn () => Property::create(['name' => $name, 'price_from' => $price, 'status' => 'available']));
    }

    private function summary(string $periode, ?User $as = null): array
    {
        Cache::flush();
        $this->actingInTenant($as ?? $this->admin, $this->shop);

        return app(DashboardData::class)->summary(Period::make($periode));
    }

    public function test_day_boundaries_follow_the_agency_time_zone(): void
    {
        $today = Period::make('today');
        $this->assertSame(['2026-11-04 00:00:00', '2026-11-04 23:59:59.999999'], $today->bounds());

        $this->leadAt('2026-11-04 23:30');
        $this->leadAt('2026-11-04 00:00');
        $this->leadAt('2026-11-03 23:59:59');

        $this->assertSame(2, $this->summary('today')['new']['now']);
        $this->assertSame(1, $this->summary('today')['new']['before'], 'Yesterday is the period before today.');

        // Ten minutes after midnight the 23:30 lead is yesterday's.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 00:10', 'Asia/Jakarta'));
        $this->assertSame(0, $this->summary('today')['new']['now']);
        $this->assertSame(2, $this->summary('yesterday')['new']['now']);

        // An agency in WITA (UTC+8): 23:30 WIB is already 00:30 on 5 November there.
        $this->shop->update(['timezone' => 'Asia/Makassar']);
        $this->assertSame(1, $this->summary('today')['new']['now']);
    }

    public function test_named_periods_and_the_periods_before_them(): void
    {
        $dates = fn (Period $p) => [$p->start->toDateString(), $p->end->toDateString()];

        $this->assertSame(['2026-11-03', '2026-11-03'], $dates(Period::make('yesterday')));
        $this->assertSame(['2026-10-29', '2026-11-04'], $dates(Period::make('7d')));
        $this->assertSame(7, Period::make('7d')->days());
        $this->assertSame(['2026-10-22', '2026-10-28'], $dates(Period::make('7d')->previous()));
        $this->assertSame(['2026-10-06', '2026-11-04'], $dates(Period::make('30d')));
        $this->assertSame(['2026-11-01', '2026-11-04'], $dates(Period::make('this_month')));
        $this->assertSame(['2026-10-01', '2026-10-04'], $dates(Period::make('this_month')->previous()));
        $this->assertSame(['2026-10-01', '2026-10-31'], $dates(Period::make('last_month')));
        $this->assertSame(['2026-09-01', '2026-09-30'], $dates(Period::make('last_month')->previous()));
        $this->assertSame('7d', Period::make('bogus')->key, 'Unknown keys fall back to 7 days.');
        $this->assertSame('30d', Period::make(null, default: '30d')->key);
    }

    public function test_custom_dates_are_swapped_and_limited_to_366_days(): void
    {
        $p = Period::make('custom', '2026-11-04', '2026-11-01');
        $this->assertSame(['2026-11-01', '2026-11-04'], [$p->start->toDateString(), $p->end->toDateString()]);
        $this->assertSame(['periode' => 'custom', 'dari' => '2026-11-01', 'sampai' => '2026-11-04'], $p->toQuery());
        $this->assertSame(['2026-10-28', '2026-10-31'], [$p->previous()->start->toDateString(), $p->previous()->end->toDateString()]);

        $this->assertSame('custom', Period::make('custom', '2025-11-04', '2026-11-04')->key, '366 days is allowed.');
        $this->assertSame('7d', Period::make('custom', '2025-11-03', '2026-11-04')->key, '367 days is refused.');
        $this->assertSame('7d', Period::make('custom', '2026-02-30', '2026-11-04')->key, 'Invalid dates are refused.');

        $this->assertNull(Period::optional('all'));
        $this->assertNull(Period::optional(null));
        $this->assertSame('custom', Period::optional(null, '2026-11-01', '2026-11-02')->key);
    }

    public function test_summary_counts_orders_paid_sales_and_compares_with_the_week_before(): void
    {
        $tas = $this->product('Tas Badminton', 349000);
        $order = $this->retailStage(RetailStages::ORDER);
        $shipping = $this->retailStage(RetailStages::SHIPPING);
        $done = $this->retailStage(RetailStages::DONE);
        $cancelled = $this->retailStage(RetailStages::CANCELLED);

        $a = $this->leadAt('2026-11-02 10:00', ['property_id' => $tas->getKey(), 'quantity' => 2, 'interest' => 'hot', 'ad_id' => '120001', 'ad_title' => 'Promo Tas']);
        $this->moveAt($a, $order, '2026-11-02 11:00');
        $this->moveAt($a, $shipping, '2026-11-03 09:00');
        $b = $this->leadAt('2026-11-03 10:00', ['order_items' => [['name' => 'Raket', 'price' => 500000, 'quantity' => 1]]]);
        $this->moveAt($b, $order, '2026-11-03 11:00');
        $this->moveAt($b, $shipping, '2026-11-04 08:00');
        $this->moveAt($b, $done, '2026-11-04 20:00');
        $c = $this->leadAt('2026-11-04 09:00');
        $this->moveAt($c, $cancelled, '2026-11-04 10:00');
        // Last week: one lead, no order.
        $this->leadAt('2026-10-25 09:00');
        // Another shop's lead in the same week is never counted.
        $other = app(ProvisionTenant::class)('Toko Lain', 'toko-lain', User::factory()->create(), 'retail');
        $this->leadAt('2026-11-03 09:00', [], $other);

        $s = $this->summary('7d');
        $this->assertSame(['now' => 3, 'before' => 1], $s['new']);
        $this->assertSame(1, $s['ads']['now']);
        $this->assertSame(1, $s['hot']['now']);
        $this->assertSame(['now' => 2, 'before' => 0], $s['orders']);
        $this->assertSame(2, $s['paid']['now']);
        $this->assertSame(1, $s['done']['now']);
        $this->assertSame(1, $s['cancelled']['now']);
        // 2 x 349.000 from the product price + 500.000 from the order lines; no shipping.
        $this->assertSame(1198000, $s['sales']['now']);
        $this->assertSame(599000, $s['average']['now']);

        // Today only: one lead in, B paid and done today, nothing ordered today.
        $t = $this->summary('today');
        $this->assertSame(1, $t['new']['now']);
        $this->assertSame(0, $t['orders']['now']);
        $this->assertSame(1, $t['paid']['now']);
        $this->assertSame(500000, $t['sales']['now']);
    }

    public function test_per_product_per_ad_per_source_and_per_agent(): void
    {
        $tas = $this->product('Tas Badminton', 349000);
        $raket = $this->product('Raket', 500000);
        $order = $this->retailStage(RetailStages::ORDER);
        $shipping = $this->retailStage(RetailStages::SHIPPING);
        $agent = $this->member($this->shop, Role::Agent);
        $form = $this->in($this->shop, fn () => LeadSource::create(['name' => 'Formulir', 'type' => 'form']));
        $wa = $this->in($this->shop, fn () => LeadSource::create(['name' => 'WA', 'type' => 'whatsapp']));

        $a = $this->leadAt('2026-11-02 10:00', ['property_id' => $tas->getKey(), 'ad_title' => 'Promo Tas', 'ad_id' => '1', 'lead_source_id' => $wa->getKey(), 'owner_id' => $agent->getKey()]);
        $this->moveAt($a, $order, '2026-11-02 11:00');
        $this->moveAt($a, $shipping, '2026-11-02 12:00');
        $this->leadAt('2026-11-03 10:00', ['property_id' => $tas->getKey(), 'ad_title' => 'Promo Tas', 'ad_id' => '1', 'interest' => 'hot', 'owner_id' => $agent->getKey()]);
        $this->leadAt('2026-11-03 10:00', ['property_id' => $raket->getKey(), 'lead_source_id' => $form->getKey()]);
        $this->leadAt('2026-11-04 10:00', ['lead_source_id' => $wa->getKey()]);
        $this->leadAt('2026-10-01 10:00', ['property_id' => $raket->getKey()]); // outside the period

        Cache::flush();
        $this->actingInTenant($this->admin, $this->shop);
        $data = app(DashboardData::class);
        $period = Period::make('7d');

        $products = collect($data->products($period))->keyBy('name');
        $this->assertSame(['leads' => 2, 'hot' => 1, 'orders' => 1, 'paid' => 1, 'value' => 349000, 'rate' => 50.0],
            collect($products['Tas Badminton'])->only(['leads', 'hot', 'orders', 'paid', 'value', 'rate'])->all());
        $this->assertSame(1, $products['Raket']['leads']);
        $this->assertSame(1, $products['Tanpa produk']['leads']);
        $this->assertNull($products['Tanpa produk']['id']);

        $ads = collect($data->ads($period))->keyBy('name');
        $this->assertSame([2, 1, 349000], [$ads['Promo Tas']['leads'], $ads['Promo Tas']['paid'], $ads['Promo Tas']['value']]);
        $this->assertSame(2, $ads['Tanpa data iklan / organik']['leads']);

        $this->assertSame([LeadOrigin::WA_ADS => 2, LeadOrigin::WA_ORGANIC => 1, LeadOrigin::FORM => 1, LeadOrigin::MANUAL => 0], $data->sources($period));

        $agents = collect($data->agents($period))->keyBy('name');
        $this->assertSame([2, 1], [$agents[$agent->name]['leads'], $agents[$agent->name]['orders']]);
        $this->assertSame(2, $agents[$this->admin->name]['leads']);

        // The agent sees only their own leads, and no team table.
        Cache::flush();
        $this->actingInTenant($agent, $this->shop);
        $this->assertSame(2, app(DashboardData::class)->summary($period)['new']['now']);
        $this->assertSame([], app(DashboardData::class)->agents($period));
        $this->assertSame(['Tas Badminton'], collect(app(DashboardData::class)->products($period))->pluck('name')->all());
    }

    public function test_the_compact_picker_is_a_dropdown_with_its_dates_beside_it(): void
    {
        $this->assertSame('4 Nov 2026 · dibanding 3 Nov', Period::caption('today', null, null, compare: true));
        $this->assertSame('29 Okt – 4 Nov 2026 · dibanding 22 Okt – 28 Okt', Period::caption('7d', null, null, compare: true));
        $this->assertSame('1 Nov – 4 Nov 2026', Period::caption('custom', '2026-11-01', '2026-11-04'));
        $this->assertSame('Pilih tanggal dari dan sampai', Period::caption('custom', null, null));
        $this->assertSame('Semua tanggal', Period::caption('all', null, null, all: true));

        Banner::create(['title' => 'Promo', 'image_path' => 'banners/x.png', 'is_active' => true, 'sort_order' => 1]);
        $this->actingInTenant($this->admin, $this->shop);

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('<select')->assertSee('Kustom…')->assertSee('29 Okt – 4 Nov 2026 · dibanding 22 Okt – 28 Okt')
            ->set('filters.periode', 'custom')->assertSee('Dari')->assertSee('Sampai');

        // The banner sits above the picker, once.
        $html = $this->get('/app/'.$this->shop->slug)->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="jl-banner-track"'));
        $this->assertLessThan(strpos($html, 'Kustom…'), strpos($html, 'class="jl-banner-track"'));
        $this->assertStringNotContainsString('Status saat ini tidak mengikuti periode', $html);

        Livewire::test(Pipeline::class)->assertSeeHtml('<select')->assertSee('Semua waktu')->assertSee('Semua tanggal');
        Livewire::test(Reports::class)->assertSeeHtml('<select')->assertSee('6 Okt – 4 Nov 2026');
    }

    public function test_dashboard_reads_the_period_from_the_link_and_every_widget_renders(): void
    {
        $this->leadAt('2026-11-04 09:00', ['name' => 'Budi Hari Ini']);
        $this->leadAt('2026-10-20 09:00');
        $this->actingInTenant($this->admin, $this->shop);

        Livewire::withQueryParams(['periode' => '30d'])->test(Dashboard::class)->assertSet('filters.periode', '30d')->assertOk();
        Livewire::withQueryParams(['dari' => '2026-11-01', 'sampai' => '2026-11-04'])->test(Dashboard::class)
            ->assertSet('filters.periode', 'custom')->assertSet('filters.dari', '2026-11-01');

        foreach ([PeriodStats::class, CurrentStatus::class, LeadFunnel::class, LeadsBySource::class, ProductPerformance::class, AdPerformance::class, AutomationActivity::class] as $widget) {
            Livewire::test($widget, ['pageFilters' => ['periode' => 'today']])->assertOk();
        }

        Livewire::test(PeriodStats::class, ['pageFilters' => ['periode' => 'today']])->assertSee('Lead baru')->assertSee('Order masuk')->assertSee('Nilai penjualan');
        $this->assertFalse(AgentPerformance::canView(), 'One person in the shop: no per-agent table.');
    }

    public function test_property_dashboard_shows_surveys_bookings_and_closings(): void
    {
        $agency = $this->makeTenant('griya-prima');
        $admin = $this->adminOf($agency);
        $lead = $this->leadAt('2026-11-02 09:00', ['survey_at' => CarbonImmutable::parse('2026-11-03 10:00', 'Asia/Jakarta'), 'deal_value' => 750000000], $agency);
        $won = Stage::withoutGlobalScopes()->where('tenant_id', $agency->getKey())->where('type', 'won')->first();
        $this->moveAt($lead, $won, '2026-11-04 15:00');

        Cache::flush();
        $this->actingInTenant($admin, $agency);
        $s = app(DashboardData::class)->summary(Period::make('7d'));
        $this->assertSame(1, $s['surveys_planned']['now']);
        $this->assertSame(1, $s['closings']['now']);
        $this->assertSame(750000000, $s['closing_value']['now']);

        Livewire::test(PeriodStats::class, ['pageFilters' => ['periode' => '7d']])->assertSee('Booking')->assertSee('Akad / closing')->assertDontSee('Order masuk');
    }

    public function test_lead_list_filters_by_period_product_origin_ad_and_stage_entry(): void
    {
        $tas = $this->product('Tas Badminton', 349000);
        $order = $this->retailStage(RetailStages::ORDER);
        $today = $this->leadAt('2026-11-04 23:30', ['name' => 'Lead Hari Ini', 'property_id' => $tas->getKey(), 'ad_title' => 'Promo Tas', 'ad_id' => '9']);
        $old = $this->leadAt('2026-10-01 10:00', ['name' => 'Lead Lama']);
        $this->moveAt($old, $order, '2026-11-03 10:00');
        $this->actingInTenant($this->admin, $this->shop);

        Livewire::test(ListLeads::class)
            ->filterTable('masuk', ['periode' => 'today'])->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$old])
            ->resetTableFilters()->assertCanSeeTableRecords([$today, $old])
            ->filterTable('produk', $tas->getKey())->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$old])
            ->resetTableFilters()->filterTable('produk', 'none')->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$today])
            ->resetTableFilters()->filterTable('sumber', LeadOrigin::WA_ADS)->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$old])
            ->resetTableFilters()->filterTable('iklan', 'Promo Tas')->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$old])
            ->resetTableFilters()->filterTable('masuk_tahap', ['tahap' => $order->getKey(), 'periode' => '7d'])->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$today])
            ->resetTableFilters()->filterTable('masuk_tahap', ['tahap' => $order->getKey(), 'periode' => 'today'])->assertCanNotSeeTableRecords([$old]);

        // A shared link (what the Dashboard tables point to) opens the list already filtered.
        Livewire::withQueryParams(['filters' => ['produk' => ['value' => (string) $tas->getKey()], 'masuk' => ['periode' => '7d']]])->test(ListLeads::class)
            ->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$old]);

        $this->get(LeadResource::getUrl('index', ['filters' => ['masuk' => ['periode' => 'today']]]))
            ->assertOk()->assertSee('Lead Hari Ini')->assertDontSee('Lead Lama');
    }

    public function test_pipeline_follows_period_and_product_with_value_per_column(): void
    {
        $tas = $this->product('Tas Badminton', 349000);
        $order = $this->retailStage(RetailStages::ORDER);
        $a = $this->leadAt('2026-11-04 09:00', ['property_id' => $tas->getKey(), 'quantity' => 2]);
        $this->moveAt($a, $order, '2026-11-04 10:00');
        $b = $this->leadAt('2026-10-01 09:00');
        $this->moveAt($b, $order, '2026-11-04 11:00');
        $this->actingInTenant($this->admin, $this->shop);

        $column = fn ($test) => collect($test->viewData('columns'))->first(fn ($c) => $c['stage']->is($order));

        $board = Livewire::test(Pipeline::class)->assertSet('periode', Period::ALL);
        $this->assertSame(2, $column($board)['count']);
        $this->assertSame(698000, $column($board)['value']);

        $board->set('periode', 'today');
        $this->assertSame(1, $column($board)['count'], 'By the date the lead came in.');
        $board->set('dasar', 'tahap');
        $this->assertSame(2, $column($board)['count'], 'By the date it entered the stage.');
        $board->set('dasar', 'lead')->set('periode', Period::ALL)->set('produk', 'none');
        $this->assertSame(1, $column($board)['count']);
        $this->assertSame(0, $column($board)['value']);

        // The choice is remembered for the next visit; a link wins over it.
        Livewire::test(Pipeline::class)->set('periode', '30d');
        Livewire::test(Pipeline::class)->assertSet('periode', '30d');
        Livewire::withQueryParams(['periode' => 'today'])->test(Pipeline::class)->assertSet('periode', 'today');
    }

    public function test_reports_use_the_same_period(): void
    {
        $this->leadAt('2026-11-04 09:00');
        $this->leadAt('2026-10-20 09:00');
        $this->actingInTenant($this->admin, $this->shop);

        Livewire::test(Reports::class)->assertSet('periode', '30d')->assertViewHas('summary', fn ($s) => $s['Lead masuk'] === '2')
            ->set('periode', 'today')->assertViewHas('summary', fn ($s) => $s['Lead masuk'] === '1')
            ->set('periode', 'custom')->set('dari', '2026-10-20')->set('sampai', '2026-10-20')->assertViewHas('summary', fn ($s) => $s['Lead masuk'] === '1');
    }
}
