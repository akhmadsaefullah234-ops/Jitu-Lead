<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Leads\LeadResource;
use App\Livewire\SupportChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The phone layout: a bottom tab bar, a floating add button on the lead list, and a safe-area viewport. */
class MobileAppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_the_bottom_tab_bar_for_every_role(): void
    {
        $tenant = $this->makeTenant();

        foreach ([$this->adminOf($tenant), $this->member($tenant, Role::Agent)] as $user) {
            $this->actingInTenant($user, $tenant);

            $this->get("/app/{$tenant->slug}")->assertOk()
                ->assertSee('class="jl-tabbar"', false)
                ->assertSee('Dasbor')->assertSee('Inbox')->assertSee('Pipeline')
                ->assertSee('viewport-fit', false);
        }
    }

    public function test_the_add_lead_button_floats_only_on_the_lead_list(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->get(LeadResource::getUrl('index'))->assertOk()->assertSee('class="jl-fab"', false);
        $this->get("/app/{$tenant->slug}")->assertOk()->assertDontSee('class="jl-fab"', false);
    }

    public function test_help_is_an_item_in_the_menu_and_opens_the_chat_on_request(): void
    {
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->get("/app/{$tenant->slug}")->assertOk()->assertSee('class="jl-menu-support"', false)->assertSee('Bantuan');

        Livewire::test(SupportChat::class)->assertSet('open', false)->dispatch('open-support')->assertSet('open', true)
            ->assertSeeHtml('jl-support--open');
        // Asking twice must not close it again.
        Livewire::test(SupportChat::class)->dispatch('open-support')->dispatch('open-support')->assertSet('open', true);
    }

    public function test_the_login_page_has_no_tab_bar(): void
    {
        auth()->logout();

        $this->get('/app/login')->assertOk()->assertDontSee('class="jl-tabbar"', false);
    }
}
