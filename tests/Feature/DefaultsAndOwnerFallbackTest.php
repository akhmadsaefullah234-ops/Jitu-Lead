<?php

namespace Tests\Feature;

use App\Actions\AssignLead;
use App\Actions\RegisterLead;
use App\Enums\Role;
use App\Models\FollowUpRule;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DefaultsAndOwnerFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        (require base_path('database/migrations/2026_10_20_000100_backfill_default_follow_up_rules.php'))->up();
    }

    private function rules(Tenant $tenant)
    {
        return FollowUpRule::withoutGlobalScopes()->where('tenant_id', $tenant->getKey());
    }

    public function test_backfill_gives_an_agency_without_rules_three_switched_off_starters(): void
    {
        $tenant = $this->makeTenant();
        $this->rules($tenant)->delete();

        $this->runBackfill();

        $this->assertSame(3, $this->rules($tenant)->count());
        $this->assertSame(0, $this->rules($tenant)->where('active', true)->count());
        $this->assertSame(['Dihubungi', 'Lead baru', 'Terkualifikasi'], DB::table('follow_up_rules')->join('stages', 'stages.id', '=', 'follow_up_rules.stage_id')
            ->where('follow_up_rules.tenant_id', $tenant->getKey())->orderBy('stages.name')->pluck('stages.name')->all());
    }

    public function test_backfill_leaves_agencies_that_already_have_rules_alone_and_is_repeatable(): void
    {
        $has = $this->makeTenant('punya-aturan');
        $this->rules($has)->where('name', 'Sapaan H+1')->update(['active' => true, 'body' => 'Teks saya sendiri']);
        $this->rules($has)->where('name', '!=', 'Sapaan H+1')->delete();
        $none = $this->makeTenant('tanpa-aturan');
        $this->rules($none)->delete();

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, $this->rules($has)->count());
        $this->assertSame('Teks saya sendiri', $this->rules($has)->first()->body);
        $this->assertTrue($this->rules($has)->first()->active);
        $this->assertSame(3, $this->rules($none)->count());
    }

    public function test_backfill_skips_a_rule_whose_stage_does_not_exist(): void
    {
        $tenant = $this->makeTenant();
        $this->rules($tenant)->delete();
        DB::table('stages')->where('tenant_id', $tenant->getKey())->where('name', 'Dihubungi')->update(['name' => 'Disapa']);

        $this->runBackfill();

        $this->assertSame(2, $this->rules($tenant)->count());
        $this->assertSame(0, $this->rules($tenant)->whereNull('stage_id')->count());
    }

    public function test_an_agency_without_agents_gives_leads_to_its_admin(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->adminOf($tenant);
        $this->member($tenant, Role::Manager);

        $lead = app(CurrentTenant::class)->run($tenant, fn () => app(RegisterLead::class)(['name' => 'Budi', 'phone' => '081234567890'], 'Formulir web', 'form')[0]);

        $this->assertSame($admin->getKey(), $lead->owner_id);
    }

    public function test_agents_still_come_first_and_an_inactive_admin_is_not_chosen(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->adminOf($tenant);
        $agent = $this->member($tenant, Role::Agent);

        $owner = app(CurrentTenant::class)->run($tenant, fn () => app(AssignLead::class)->nextAgent());
        $this->assertSame($agent->getKey(), $owner->getKey());

        $second = $this->member($tenant, Role::Admin);
        $tenant->users()->updateExistingPivot($second->getKey(), ['status' => 'inactive']);
        $tenant->users()->updateExistingPivot($agent->getKey(), ['status' => 'inactive']);

        $owner = app(CurrentTenant::class)->run($tenant, fn () => app(AssignLead::class)->nextAgent());
        $this->assertSame($admin->getKey(), $owner->getKey());
    }
}
