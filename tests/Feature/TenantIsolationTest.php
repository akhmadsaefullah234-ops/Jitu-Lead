<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Models\Lead;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_only_return_the_current_tenants_leads(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');
        $this->makeLead($a, ['name' => 'Lead A']);
        $this->makeLead($b, ['name' => 'Lead B']);

        app(CurrentTenant::class)->set($a);

        $this->assertSame(['Lead A'], Lead::pluck('name')->all());
    }

    public function test_a_member_cannot_open_another_tenants_panel(): void
    {
        $a = $this->makeTenant('agensi-a');
        $this->makeTenant('agensi-b');

        $this->actingAs($this->adminOf($a))
            ->get('/app/agensi-b/pipeline')
            ->assertNotFound();
    }

    public function test_a_lead_from_another_tenant_cannot_be_opened_by_id(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');
        $foreign = $this->makeLead($b);

        $this->actingAs($this->adminOf($a))
            ->get(LeadResource::getUrl('edit', ['record' => $foreign], tenant: $a))
            ->assertNotFound();
    }

    public function test_a_lead_from_another_tenant_cannot_be_edited_through_livewire(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');
        $foreign = $this->makeLead($b);

        $this->actingInTenant($this->adminOf($a), $a);

        Livewire::test(EditLead::class, ['record' => $foreign->getKey()])
            ->assertNotFound();
    }

    public function test_creating_a_lead_stamps_the_current_tenant(): void
    {
        $a = $this->makeTenant('agensi-a');
        $this->actingInTenant($this->adminOf($a), $a);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Budi Santoso', 'phone' => '0812 3456 7710'])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::withoutGlobalScopes()->where('name', 'Budi Santoso')->firstOrFail();
        $this->assertSame($a->getKey(), $lead->tenant_id);
        $this->assertSame('+6281234567710', $lead->phone);
    }

    public function test_a_lead_cannot_be_assigned_to_a_user_of_another_tenant(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');
        $outsider = $this->member($b, Role::Agent);
        $this->actingInTenant($this->adminOf($a), $a);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Budi Santoso', 'phone' => '081234567710', 'owner_id' => $outsider->getKey()])
            ->call('create')
            ->assertHasFormErrors(['owner_id']);

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_a_lead_source_of_another_tenant_cannot_be_used(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');
        $foreignSource = $b->leadSources()->withoutGlobalScopes()->first();
        $this->actingInTenant($this->adminOf($a), $a);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Budi Santoso', 'phone' => '081234567710', 'lead_source_id' => $foreignSource->getKey()])
            ->call('create')
            ->assertHasFormErrors(['lead_source_id']);
    }

    public function test_a_model_cannot_be_moved_to_another_tenant(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');
        $lead = $this->makeLead($a);

        $this->expectException(LogicException::class);
        $lead->forceFill(['tenant_id' => $b->getKey()])->save();
    }

    public function test_a_model_cannot_be_created_for_another_tenant(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');

        $this->expectException(LogicException::class);
        app(CurrentTenant::class)->run($a, fn () => (new Lead)->forceFill([
            'tenant_id' => $b->getKey(),
            'name' => 'Salah tenant',
            'stage_id' => $this->stage($b, 'Lead baru')->getKey(),
        ])->save());
    }

    public function test_a_lead_cannot_point_at_records_of_another_tenant(): void
    {
        $a = $this->makeTenant('agensi-a');
        $b = $this->makeTenant('agensi-b');

        $this->expectException(LogicException::class);
        $this->makeLead($a, ['stage_id' => $this->stage($b, 'Lead baru')->getKey()]);
    }
}
