<?php

namespace Tests\Feature;

use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A lead that was just added must be visible on the first page of the Lead list. */
class LeadListVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lead_added_by_hand_shows_on_the_first_page_among_many_overdue_leads(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        foreach (range(1, 30) as $i) {
            $this->makeLead($tenant, ['name' => "Lama $i", 'next_action_due_at' => now()->subDays(40 - $i)]);
        }

        Livewire::test(CreateLead::class)->fillForm(['name' => 'Baru Manual', 'phone' => '081377778888'])->call('create')->assertHasNoFormErrors();
        $lead = Lead::query()->where('name', 'Baru Manual')->firstOrFail();

        Livewire::test(ListLeads::class)->assertCanSeeTableRecords([$lead]);
    }

    public function test_a_lead_from_the_web_form_shows_on_the_first_page_among_many_overdue_leads(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        foreach (range(1, 30) as $i) {
            $this->makeLead($tenant, ['name' => "Lama $i", 'next_action_due_at' => now()->subDays(40 - $i)]);
        }

        $token = $tenant->regenerateCaptureToken();
        $this->postJson("/capture/{$token}", ['name' => 'Dari Formulir', 'phone' => '081355550000'])->assertOk();
        $lead = Lead::query()->where('name', 'Dari Formulir')->firstOrFail();

        Livewire::test(ListLeads::class)->assertCanSeeTableRecords([$lead]);
    }

    public function test_sorting_by_due_date_is_still_available(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $late = $this->makeLead($tenant, ['name' => 'Paling terlambat', 'next_action_due_at' => now()->subDays(5)]);
        $soon = $this->makeLead($tenant, ['name' => 'Segera', 'next_action_due_at' => now()->addDay()]);

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords([$soon, $late], inOrder: true)
            ->sortTable('next_action_due_at')
            ->assertCanSeeTableRecords([$late, $soon], inOrder: true);
    }
}
