<?php

namespace Tests\Feature;

use App\Actions\AssignLead;
use App\Actions\ReassignLead;
use App\Enums\Role;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Team\Pages\ManageTeam;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadDistributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    private function agent(?int $share = null, ?bool $takes = null, Role $role = Role::Agent): User
    {
        $user = User::factory()->create();
        $this->tenant->users()->attach($user, ['role' => $role->value, 'status' => 'active', 'lead_share' => $share, 'takes_leads' => $takes]);

        return $user;
    }

    /** @return array<int, int> owner id => number of leads, after giving out $n new leads */
    private function give(int $n): array
    {
        app(CurrentTenant::class)->run($this->tenant, function () use ($n) {
            foreach (range(1, $n) as $i) {
                $owner = app(AssignLead::class)->nextAgent();
                Lead::create(['name' => "L$i", 'phone' => '08123'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'stage_id' => $this->stage($this->tenant, 'Lead baru')->getKey(), 'owner_id' => $owner->getKey()]);
            }
        });

        return Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->getKey())->selectRaw('owner_id, count(*) n')->groupBy('owner_id')->pluck('n', 'owner_id')->all();
    }

    private function age(Lead $lead, int $minutes): void
    {
        $lead->forceFill(['assigned_at' => now()->subMinutes($minutes)])->saveQuietly();
    }

    public function test_leads_are_split_by_the_percentage_of_each_agent(): void
    {
        $a = $this->agent(60);
        $b = $this->agent(40);

        $counts = $this->give(10);

        $this->assertSame(6, $counts[$a->getKey()]);
        $this->assertSame(4, $counts[$b->getKey()]);
    }

    public function test_without_percentages_leads_are_shared_equally(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $c = $this->agent();

        $counts = $this->give(9);

        $this->assertSame([3, 3, 3], [$counts[$a->getKey()], $counts[$b->getKey()], $counts[$c->getKey()]]);
    }

    public function test_an_agent_at_zero_percent_gets_nothing(): void
    {
        $a = $this->agent(100);
        $b = $this->agent(0);

        $counts = $this->give(5);

        $this->assertSame(5, $counts[$a->getKey()]);
        $this->assertArrayNotHasKey($b->getKey(), $counts);
    }

    public function test_the_main_agent_can_join_the_rotation_and_otherwise_does_not_get_leads(): void
    {
        $admin = $this->adminOf($this->tenant);
        $agent = $this->agent();

        $this->assertSame([$agent->getKey() => 4], $this->give(4));

        $this->tenant->users()->updateExistingPivot($admin->getKey(), ['takes_leads' => true]);
        $counts = $this->give(4);

        $this->assertSame(4, $counts[$admin->getKey()]);
        $this->assertSame(4, $counts[$agent->getKey()]);
    }

    public function test_an_inactive_agent_is_skipped(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $this->tenant->users()->updateExistingPivot($b->getKey(), ['status' => 'inactive']);

        $this->assertSame([$a->getKey() => 3], $this->give(3));
    }

    public function test_a_lead_nobody_answers_moves_to_the_next_agent_when_set_to_automatic(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $this->tenant->forceFill(['lead_reassign_mode' => 'auto', 'lead_reassign_minutes' => 30])->save();
        $lead = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);
        $this->age($lead, 45);

        $this->assertSame(1, app(ReassignLead::class)->stale($this->tenant));

        $lead->refresh();
        $this->assertSame($b->getKey(), $lead->owner_id);
        $this->assertSame(1, $lead->reassign_count);
        $this->assertTrue($lead->assigned_at->greaterThan(now()->subMinute()));
        $this->assertSame('reassigned', $lead->activities()->latest('id')->first()->type);

        // The new owner gets their own time, and the lead does not bounce back to the first agent.
        $this->assertSame(0, app(ReassignLead::class)->stale($this->tenant));
        $this->age($lead, 45);
        $this->assertSame(0, app(ReassignLead::class)->stale($this->tenant));
        $this->assertSame($b->getKey(), $lead->refresh()->owner_id);
    }

    public function test_nothing_moves_in_manual_mode_or_when_the_owner_reacted_or_it_is_early_or_closed(): void
    {
        $a = $this->agent();
        $this->agent();
        $this->tenant->forceFill(['lead_reassign_minutes' => 30])->save();

        $quiet = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);
        $early = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);
        $answered = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);
        $won = $this->makeLead($this->tenant, ['owner_id' => $a->getKey(), 'stage_id' => $this->stage($this->tenant, 'Closing')->getKey()]);
        foreach ([$quiet, $answered, $won] as $lead) {
            $this->age($lead, 45);
        }
        $this->age($early, 10);
        app(CurrentTenant::class)->run($this->tenant, fn () => $answered->activities()->create(['user_id' => $a->getKey(), 'type' => 'note', 'body' => 'Sudah dihubungi']));

        $this->assertSame(0, app(ReassignLead::class)->stale($this->tenant), 'manual mode never moves by itself');

        $this->tenant->forceFill(['lead_reassign_mode' => 'auto'])->save();
        $this->assertSame(1, app(ReassignLead::class)->stale($this->tenant->refresh()));
        $this->assertNotSame($a->getKey(), $quiet->refresh()->owner_id);
        $this->assertSame($a->getKey(), $early->refresh()->owner_id);
        $this->assertSame($a->getKey(), $answered->refresh()->owner_id);
        $this->assertSame($a->getKey(), $won->refresh()->owner_id);
    }

    public function test_an_ai_reply_counts_as_attended(): void
    {
        $a = $this->agent();
        $this->agent();
        $this->tenant->forceFill(['lead_reassign_mode' => 'auto'])->save();
        $lead = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);
        $this->age($lead, 60);
        app(CurrentTenant::class)->run($this->tenant, fn () => $lead->activities()->create(['user_id' => null, 'type' => 'ai_reply', 'body' => 'AI membalas']));

        $this->assertSame(0, app(ReassignLead::class)->stale($this->tenant));
    }

    public function test_a_lead_is_moved_at_most_the_set_number_of_times(): void
    {
        $a = $this->agent();
        $this->agent();
        $this->agent();
        $this->tenant->forceFill(['lead_reassign_mode' => 'auto', 'lead_reassign_max' => 1])->save();
        $lead = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);

        $this->age($lead, 45);
        $this->assertSame(1, app(ReassignLead::class)->stale($this->tenant));
        $this->age($lead, 45);
        $this->assertSame(0, app(ReassignLead::class)->stale($this->tenant));
    }

    public function test_the_command_only_touches_agencies_set_to_automatic(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $lead = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);
        $this->age($lead, 45);

        $this->artisan('leads:reassign')->assertSuccessful();
        $this->assertSame($a->getKey(), $lead->refresh()->owner_id);

        $this->tenant->forceFill(['lead_reassign_mode' => 'auto'])->save();
        $this->artisan('leads:reassign')->assertSuccessful();
        $this->assertSame($b->getKey(), $lead->refresh()->owner_id);
    }

    public function test_an_admin_moves_a_lead_by_hand_and_an_agent_cannot(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $lead = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);
        $this->age($lead, 45);

        $this->actingInTenant($a, $this->tenant);
        Livewire::test(ListLeads::class)->assertTableActionHidden('move', $lead);

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(ListLeads::class)
            ->filterTable('unattended')->assertCanSeeTableRecords([$lead])
            ->callTableAction('move', $lead, ['to' => $b->getKey()]);

        $this->assertSame($b->getKey(), $lead->refresh()->owner_id);
        $this->assertSame(0, $lead->reassign_count - 1);
    }

    public function test_bulk_move_sends_selected_leads_to_the_next_agent(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $lead = $this->makeLead($this->tenant, ['owner_id' => $a->getKey()]);

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(ListLeads::class)->callTableBulkAction('moveNext', [$lead]);

        $this->assertSame($b->getKey(), $lead->refresh()->owner_id);
    }

    public function test_the_main_agent_sees_every_chat_with_the_agent_in_charge(): void
    {
        $a = $this->agent();
        $mine = $this->makeLead($this->tenant, ['name' => 'Chat Milik Agen', 'owner_id' => $a->getKey()]);
        app(CurrentTenant::class)->run($this->tenant, fn () => WaConversation::create(['lead_id' => $mine->getKey(), 'phone' => $mine->phone]));

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(Inbox::class)->assertSee('Chat Milik Agen')->assertSee('Agen: '.$a->name);
    }

    public function test_the_team_screen_saves_the_move_settings_and_a_share(): void
    {
        $agent = $this->agent(50);
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageTeam::class)
            ->callAction('distribution', ['mode' => 'auto', 'minutes' => 20, 'max' => 3])
            ->assertHasNoActionErrors();

        $this->tenant->refresh();
        $this->assertSame(['auto', 20, 3], [$this->tenant->lead_reassign_mode, $this->tenant->lead_reassign_minutes, $this->tenant->lead_reassign_max]);

        $membership = Membership::query()->where('tenant_id', $this->tenant->getKey())->where('user_id', $agent->getKey())->firstOrFail();
        Livewire::test(ManageTeam::class)
            ->callTableAction('edit', $membership, ['role' => 'agent', 'status' => 'active', 'takes_leads' => true, 'lead_share' => 70])
            ->assertHasNoTableActionErrors();

        $this->assertSame(70, (int) $this->tenant->users()->whereKey($agent->getKey())->first()->pivot->lead_share);
    }
}
