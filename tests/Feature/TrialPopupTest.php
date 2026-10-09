<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Auth\Register;
use App\Livewire\PlanPopup;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TrialPopupTest extends TestCase
{
    use RefreshDatabase;

    private function endTrial(Tenant $tenant): void
    {
        $tenant->currentSubscription()->update(['trial_ends_at' => now()->subMinute()]);
    }

    public function test_a_new_agency_is_on_a_14_day_trial_and_sees_no_popup(): void
    {
        $tenant = $this->makeTenant();
        $sub = $tenant->currentSubscription();

        $this->assertSame(Subscription::TRIAL, $sub->effectiveStatus());
        $this->assertEqualsWithDelta(14, now()->diffInDays($sub->trial_ends_at), 1);

        $this->actingInTenant($this->adminOf($tenant), $tenant);
        Livewire::test(PlanPopup::class)->assertDontSee('Pilih paket');
    }

    public function test_the_popup_warns_in_the_last_days_and_can_be_snoozed(): void
    {
        $tenant = $this->makeTenant();
        $tenant->currentSubscription()->update(['trial_ends_at' => now()->addDays(2)]);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        Livewire::test(PlanPopup::class)->assertSee('Pilih paket')->call('snooze')->assertDontSee('Pilih paket');
    }

    public function test_an_ended_trial_is_read_only_at_once_and_the_popup_asks_for_a_plan(): void
    {
        $tenant = $this->makeTenant();
        $this->endTrial($tenant);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $sub = $tenant->currentSubscription()->fresh();
        $this->assertTrue($sub->isReadOnly());
        $this->assertSame('trial_ended', Livewire::test(PlanPopup::class)->instance()->state());
        Livewire::test(PlanPopup::class)->assertSee('Mandiri')->assertSee('Tim')->assertSee('Agensi');
    }

    public function test_an_admin_picks_a_plan_and_a_request_is_recorded(): void
    {
        $tenant = $this->makeTenant();
        $this->endTrial($tenant);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        Livewire::test(PlanPopup::class)->set('cycle', 'yearly')->call('choose', 'agensi');

        $request = SubscriptionRequest::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->first();
        $this->assertSame(['agensi', 'yearly'], [$request->plan, $request->billing_cycle]);
    }

    public function test_a_non_admin_cannot_choose_a_plan_and_is_told_who_to_ask(): void
    {
        $tenant = $this->makeTenant();
        $this->endTrial($tenant);
        $agent = $this->member($tenant, Role::Agent);
        $this->actingInTenant($agent, $tenant);

        Livewire::test(PlanPopup::class)->assertSee($this->adminOf($tenant)->name)->call('choose', 'tim')->assertForbidden();
        $this->assertSame(0, SubscriptionRequest::withoutGlobalScopes()->count());
    }

    public function test_the_register_heading_announces_the_free_trial_when_open(): void
    {
        config(['jitu.registration' => 'open']);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->assertStringContainsString('coba gratis 14 hari', (string) Livewire::test(Register::class)->instance()->getHeading());
    }

    public function test_the_popup_and_the_plan_badge_are_on_every_panel_page(): void
    {
        $tenant = $this->makeTenant();
        $this->endTrial($tenant);
        $admin = $this->adminOf($tenant);

        $this->actingAs($admin)->get('/app/'.$tenant->slug)->assertOk()->assertSee('Masa percobaan 14 hari Anda sudah berakhir', false);
    }
}
