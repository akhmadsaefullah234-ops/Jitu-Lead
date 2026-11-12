<?php

namespace Tests\Feature;

use App\Filament\Pages\Pipeline;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Team\Pages\ManageTeam;
use App\Filament\Resources\WaChannels\Pages\ListWaChannels;
use App\Filament\Support\InitialsAvatarProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** On phones the data fits the screen: nothing needs scrolling sideways. */
class MobileLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->makeTenant();
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $this->makeLead($tenant, ['name' => 'Budi']);
    }

    public function test_tables_turn_into_cards_on_phones(): void
    {
        foreach ([ListLeads::class, ManageTeam::class, ListWaChannels::class] as $page) {
            $this->assertTrue(Livewire::test($page)->instance()->getTable()->isStackedOnMobile(), $page);
        }

        Livewire::test(ListLeads::class)->assertSeeHtml('fi-ta-table-stacked-on-mobile');
    }

    public function test_the_pipeline_shows_stage_tabs_for_phones(): void
    {
        Livewire::test(Pipeline::class)
            ->assertSeeHtml('class="jl-tabs"')
            ->assertSeeHtml("'is-current': stage ===")
            ->assertSeeHtml('x-ref="board"')
            ->assertSeeHtml('x-on:click="go(')
            ->assertSeeHtml('aria-controls="jl-filters"');
    }

    public function test_lead_cards_keep_secondary_fields_behind_a_detail_toggle(): void
    {
        Livewire::test(ListLeads::class)
            ->assertSeeHtml('jl-detail-check')
            ->assertSee('Lihat detail')
            ->assertSeeHtml('jl-detail');
    }

    public function test_the_table_filter_button_carries_a_label(): void
    {
        $action = Livewire::test(ListLeads::class)->instance()->getTable()->getFiltersTriggerAction();

        $this->assertSame('Filter', $action->getLabel());
        $this->assertTrue($action->isButton());
    }

    public function test_report_tables_label_each_figure_for_the_card_view(): void
    {
        Livewire::test(Reports::class)->assertSeeHtml('data-label="Konversi"');
    }

    public function test_form_buttons_stay_in_reach_while_a_long_form_scrolls(): void
    {
        $this->assertTrue(Livewire::test(CreateLead::class)->instance()->areFormActionsSticky());
    }

    public function test_the_filter_button_says_how_many_filters_are_on_so_a_zero_count_can_be_hidden(): void
    {
        Livewire::test(ListLeads::class)->assertSeeHtml('data-active-filters="0"');
    }

    public function test_avatars_are_drawn_locally_from_initials(): void
    {
        $avatar = app(InitialsAvatarProvider::class)->get($this->adminOf($this->makeTenant('toko-avatar')));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $this->assertStringNotContainsString('ui-avatars.com', $avatar);
        $this->assertMatchesRegularExpression('#<text[^>]*>\p{Lu}{1,2}</text>#u', base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,'))));
    }
}
