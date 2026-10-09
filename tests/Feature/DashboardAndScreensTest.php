<?php

namespace Tests\Feature;

use App\Actions\DemoData;
use App\Enums\Role;
use App\Filament\Pages\AiAssistant;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports;
use App\Filament\Pages\Tasks;
use App\Filament\Resources\Properties\Pages\ManageProperties;
use App\Filament\Widgets\OverdueTasks;
use App\Filament\Widgets\RecentLeads;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardAndScreensTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    private function inTenant(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->tenant, $fn);
    }

    public function test_empty_dashboard_guides_and_admin_loads_then_clears_demo_data(): void
    {
        $this->member($this->tenant, Role::Agent);
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(Dashboard::class)->assertSee('Selamat datang di JITU LEAD')->assertSee('Isi data contoh')
            ->callAction('loadDemo');

        $this->assertSame(31, $this->inTenant(fn () => Lead::count()));
        $this->assertGreaterThan(0, $this->inTenant(fn () => Lead::overdue()->count()));
        $this->assertSame(4, $this->inTenant(fn () => Property::count()));

        Livewire::test(Dashboard::class)->assertDontSee('Selamat datang di JITU LEAD')->assertSee('Hapus data contoh')->assertDontSee('Isi data contoh')
            ->callAction('clearDemo');

        $this->assertSame(0, $this->inTenant(fn () => Lead::withTrashed()->count()));
        $this->assertSame(0, $this->inTenant(fn () => Property::count()));
    }

    public function test_clearing_demo_data_keeps_real_data_and_other_agencies(): void
    {
        $other = $this->makeTenant('rumah-lain');
        $this->inTenant(fn () => app(DemoData::class)->load(), $other);
        $real = $this->makeLead($this->tenant, ['name' => 'Lead Asli']);

        $this->inTenant(fn () => app(DemoData::class)->load());
        $this->assertSame(32, $this->inTenant(fn () => Lead::count()));

        $this->inTenant(fn () => app(DemoData::class)->clear());

        $this->assertSame([$real->getKey()], $this->inTenant(fn () => Lead::pluck('id')->all()));
        $this->assertSame(31, $this->inTenant(fn () => Lead::count(), $other));
    }

    public function test_only_admin_gets_demo_actions(): void
    {
        foreach ([Role::Agent, Role::Manager] as $role) {
            $this->actingInTenant($this->member($this->tenant, $role), $this->tenant);
            Livewire::test(Dashboard::class)->assertDontSee('Isi data contoh');
        }
    }

    public function test_agent_dashboard_counts_only_own_leads(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $this->makeLead($this->tenant, ['name' => 'Punya Agen', 'owner_id' => $agent->getKey(), 'next_action_due_at' => now()->subHour()]);
        $this->makeLead($this->tenant, ['name' => 'Punya Admin', 'next_action_due_at' => now()->subHour()]);

        $this->actingInTenant($agent, $this->tenant);
        Livewire::test(RecentLeads::class)->assertSee('Punya Agen')->assertDontSee('Punya Admin');
        Livewire::test(OverdueTasks::class)->assertSee('Punya Agen')->assertDontSee('Punya Admin');

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(RecentLeads::class)->assertSee('Punya Agen')->assertSee('Punya Admin');
    }

    public function test_tasks_page_lists_own_open_tasks_and_records_completion(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $mine = $this->makeLead($this->tenant, ['name' => 'Tugas Saya', 'owner_id' => $agent->getKey(), 'next_action_due_at' => now()->subHour()]);
        $this->makeLead($this->tenant, ['name' => 'Tugas Orang Lain']);

        $this->actingInTenant($agent, $this->tenant);
        $due = now()->addDay()->format('Y-m-d H:i:s');

        Livewire::test(Tasks::class)->assertSee('Tugas Saya')->assertDontSee('Tugas Orang Lain')
            ->callTableAction('done', $mine, ['result' => 'Sudah dihubungi, minta brosur', 'next_action' => 'Kirim brosur', 'due' => $due])
            ->assertHasNoTableActionErrors();

        $mine->refresh();
        $this->assertSame('Kirim brosur', $mine->next_action);
        $this->assertTrue($mine->next_action_due_at->isFuture());
        $this->assertTrue($this->inTenant(fn () => $mine->activities()->where('type', 'task_done')->where('body', 'like', '%Sudah dihubungi%')->exists()));
    }

    public function test_reports_page_scopes_to_what_the_user_may_see(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $this->makeLead($this->tenant, ['name' => 'A', 'owner_id' => $agent->getKey()]);
        $this->makeLead($this->tenant, ['name' => 'B']);

        $this->actingInTenant($agent, $this->tenant);
        Livewire::test(Reports::class)->assertOk()->assertViewHas('summary', fn ($s) => $s['Lead masuk'] === '1');

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(Reports::class)->assertViewHas('summary', fn ($s) => $s['Lead masuk'] === '2')->call('$set', 'days', 7)->assertOk();
    }

    public function test_property_permissions(): void
    {
        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        Livewire::test(ManageProperties::class)->assertOk()->assertActionHidden('create');

        $this->actingInTenant($this->member($this->tenant, Role::Manager), $this->tenant);
        Livewire::test(ManageProperties::class)
            ->callAction('create', ['kind' => 'primary', 'name' => 'Cluster Baru', 'status' => 'available', 'price_from' => 500000000])
            ->assertHasNoActionErrors();

        $property = $this->inTenant(fn () => Property::where('name', 'Cluster Baru')->firstOrFail());
        $this->assertSame($this->tenant->getKey(), $property->tenant_id);
        $this->assertFalse($this->member($this->tenant, Role::Manager)->can('delete', $property));
        $this->assertTrue($this->adminOf($this->tenant)->can('delete', $property));
    }

    public function test_planned_features_have_menu_pages(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(AiAssistant::class)->assertSee('Segera hadir');
    }
}
