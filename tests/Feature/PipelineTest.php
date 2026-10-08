<?php

namespace Tests\Feature;

use App\Actions\MoveLeadToStage;
use App\Enums\Role;
use App\Filament\Pages\Pipeline;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use App\Models\LostReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_tenant_gets_the_property_pipeline(): void
    {
        $tenant = $this->makeTenant();

        $this->assertSame(
            ['Lead baru', 'Dihubungi', 'Terkualifikasi', 'Jadwal survei', 'Sudah survei', 'Negosiasi', 'Booking', 'Closing', 'Gugur'],
            $tenant->stages()->withoutGlobalScopes()->pluck('name')->all(),
        );
    }

    public function test_moving_a_lead_fills_the_next_action_from_the_stage(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        app(MoveLeadToStage::class)($lead, $this->stage($tenant, 'Dihubungi'), $this->adminOf($tenant));

        $lead->refresh();
        $this->assertSame('Dihubungi', $lead->stage->name);
        $this->assertSame('Gali kebutuhan: tipe, lokasi, budget, cara bayar', $lead->next_action);
        $this->assertTrue($lead->next_action_due_at->equalTo(now()->addHours(48)));
        $this->assertSame('stage_changed', $lead->activities()->first()->type);
    }

    public function test_a_survey_needs_a_future_date_and_a_location(): void
    {
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $move = app(MoveLeadToStage::class);

        try {
            $move($lead, $this->stage($tenant, 'Jadwal survei'), $this->adminOf($tenant), ['survey_at' => now()->subDay(), 'survey_location' => '']);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('survey_at', $e->errors());
            $this->assertArrayHasKey('survey_location', $e->errors());
        }

        $visit = now()->addDays(3)->setTime(10, 0);
        $move($lead, $this->stage($tenant, 'Jadwal survei'), $this->adminOf($tenant), ['survey_at' => $visit, 'survey_location' => 'Unit contoh']);

        $lead->refresh();
        $this->assertTrue($lead->survey_at->equalTo($visit));
        $this->assertTrue($lead->next_action_due_at->equalTo($visit->copy()->subDay()), 'Confirmation is due the day before the visit.');
    }

    public function test_losing_a_lead_needs_a_reason_and_clears_the_next_action(): void
    {
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $move = app(MoveLeadToStage::class);

        $this->expectExceptionObject(ValidationException::withMessages(['lost_reason_id' => 'Pilih alasan gugur.']));
        try {
            $move($lead, $this->stage($tenant, 'Gugur'), $this->adminOf($tenant));
        } finally {
            $reason = LostReason::query()->where('label', 'KPR ditolak')->first();
            $move($lead->fresh(), $this->stage($tenant, 'Gugur'), $this->adminOf($tenant), ['lost_reason_id' => $reason->getKey()]);
            $this->assertNull($lead->fresh()->next_action);
            $this->assertSame('KPR ditolak', $lead->fresh()->lostReason->label);
        }
    }

    public function test_a_booking_needs_a_unit_and_a_deal_value(): void
    {
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->expectException(ValidationException::class);
        app(MoveLeadToStage::class)($lead, $this->stage($tenant, 'Booking'), $this->adminOf($tenant), ['unit' => '']);
    }

    public function test_the_board_moves_a_lead_without_requirements_directly(): void
    {
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        Livewire::test(Pipeline::class)
            ->call('moveLead', $lead->getKey(), $this->stage($tenant, 'Dihubungi')->getKey())
            ->assertNotified();

        $this->assertSame('Dihubungi', $lead->fresh()->stage->name);
    }

    public function test_the_board_asks_for_survey_details_before_moving(): void
    {
        $tenant = $this->makeTenant();
        $lead = $this->makeLead($tenant);
        $survey = $this->stage($tenant, 'Jadwal survei');
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        Livewire::test(Pipeline::class)
            ->call('moveLead', $lead->getKey(), $survey->getKey())
            ->assertActionMounted('move')
            ->callMountedAction()
            ->assertHasFormErrors(['survey_at' => 'required', 'survey_location' => 'required'])
            ->fillForm(['survey_at' => now()->addDays(2)->format('Y-m-d H:i'), 'survey_location' => 'Unit contoh Bukit Asri'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame('Jadwal survei', $lead->fresh()->stage->name);
        $this->assertSame('Unit contoh Bukit Asri', $lead->fresh()->survey_location);
    }

    public function test_agents_only_see_and_move_their_own_leads(): void
    {
        $tenant = $this->makeTenant();
        $rina = $this->member($tenant, Role::Agent);
        $dimas = $this->member($tenant, Role::Agent);
        $mine = $this->makeLead($tenant, ['name' => 'Milik Rina', 'owner_id' => $rina->getKey()]);
        $theirs = $this->makeLead($tenant, ['name' => 'Milik Dimas', 'owner_id' => $dimas->getKey()]);
        $this->actingInTenant($rina, $tenant);

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        Livewire::test(Pipeline::class)
            ->assertSee('Milik Rina')
            ->assertDontSee('Milik Dimas');

        Livewire::test(EditLead::class, ['record' => $theirs->getKey()])->assertNotFound();

        Livewire::test(Pipeline::class)
            ->call('moveLead', $theirs->getKey(), $this->stage($tenant, 'Dihubungi')->getKey())
            ->assertNotFound();

        $this->assertSame('Lead baru', $theirs->fresh()->stage->name);
    }

    public function test_agents_cannot_delete_leads(): void
    {
        $tenant = $this->makeTenant();
        $rina = $this->member($tenant, Role::Agent);
        $lead = $this->makeLead($tenant, ['owner_id' => $rina->getKey()]);
        $this->actingInTenant($rina, $tenant);

        $this->assertFalse($rina->can('delete', $lead));
        $this->assertTrue($rina->can('update', $lead));
    }

    public function test_an_agent_who_adds_a_lead_owns_it(): void
    {
        $tenant = $this->makeTenant();
        $rina = $this->member($tenant, Role::Agent);
        $this->actingInTenant($rina, $tenant);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Walk-in Sabtu', 'phone' => '081377778888'])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::query()->where('name', 'Walk-in Sabtu')->firstOrFail();
        $this->assertSame($rina->getKey(), $lead->owner_id);
        $this->assertSame('Lead baru', $lead->stage->name);
        $this->assertNotNull($lead->next_action_due_at);
    }

    public function test_unassigned_leads_go_round_robin_to_agents(): void
    {
        $tenant = $this->makeTenant();
        $rina = $this->member($tenant, Role::Agent);
        $dimas = $this->member($tenant, Role::Agent);
        $cuti = $this->member($tenant, Role::Agent);
        $tenant->users()->updateExistingPivot($cuti->getKey(), ['status' => 'leave']);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $owners = collect(['Satu', 'Dua', 'Tiga', 'Empat'])->map(function ($name) {
            $this->travel(1)->minutes();
            Livewire::test(CreateLead::class)->fillForm(['name' => $name, 'phone' => '0812'.random_int(10000000, 99999999)])->call('create');

            return Lead::query()->where('name', $name)->value('owner_id');
        });

        $this->assertSame([$rina->getKey(), $dimas->getKey(), $rina->getKey(), $dimas->getKey()], $owners->all());
        $this->assertNotContains($cuti->getKey(), $owners);
    }

    public function test_duplicate_phone_numbers_are_flagged(): void
    {
        $tenant = $this->makeTenant();
        $this->makeLead($tenant, ['name' => 'Budi lama', 'phone' => '+62 812-3456-7710']);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Budi baru', 'phone' => '0812 3456 7710'])
            ->call('create')
            ->assertNotified('Kemungkinan lead ganda');
    }
}
