<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use App\Models\Property;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Agencies that do not sell property can hide the property fields of a lead. */
class PropertyFieldsSettingTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY_FIELDS = ['need', 'property_type', 'location', 'payment_method', 'budget_min', 'budget_max', 'property_id'];

    public function test_property_fields_are_shown_by_default(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->assertTrue($tenant->fresh()->showsPropertyFields());

        $page = Livewire::test(CreateLead::class);
        foreach (self::PROPERTY_FIELDS as $field) {
            $page->assertFormFieldVisible($field);
        }
    }

    public function test_an_admin_can_hide_property_fields_from_the_lead_form_and_list(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $property = Property::create(['kind' => 'primary', 'name' => 'Griya Asri Blok C', 'property_type' => 'Rumah']);
        $lead = $this->makeLead($tenant, ['name' => 'Budi', 'property_id' => $property->getKey()]);

        Livewire::test(ListLeads::class)->assertSee('Griya Asri Blok C')
            ->callAction('agencySettings', data: ['show_property_fields' => false])->assertHasNoActionErrors();

        $this->assertFalse($tenant->fresh()->showsPropertyFields());
        app(CurrentTenant::class)->set($tenant->fresh());

        Livewire::test(ListLeads::class)->assertCanSeeTableRecords([$lead])->assertDontSee('Griya Asri Blok C');

        $page = Livewire::test(EditLead::class, ['record' => $lead->getKey()]);
        foreach (self::PROPERTY_FIELDS as $field) {
            $page->assertFormFieldHidden($field);
        }

        Livewire::test(CreateLead::class)->fillForm(['name' => 'Tanpa Properti', 'phone' => '081377778888'])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Lead::query()->where('name', 'Tanpa Properti')->exists());
        $this->assertSame($property->getKey(), $lead->fresh()->property_id, 'Saved data is kept');
    }

    public function test_agents_cannot_change_the_agency_setting(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->member($tenant, Role::Agent), $tenant);

        Livewire::test(ListLeads::class)->assertActionHidden('agencySettings');
    }

    public function test_the_platform_sales_agency_never_shows_property_fields(): void
    {
        $tenant = $this->makeTenant();
        config(['jitu.platform_sales_tenant' => $tenant->slug]);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->assertTrue((bool) $tenant->fresh()->show_property_fields);
        $this->assertFalse($tenant->fresh()->showsPropertyFields());

        $page = Livewire::test(CreateLead::class);
        foreach (self::PROPERTY_FIELDS as $field) {
            $page->assertFormFieldHidden($field);
        }

        $other = $this->makeTenant('agensi-lain');
        $this->assertTrue($other->fresh()->showsPropertyFields());
    }
}
