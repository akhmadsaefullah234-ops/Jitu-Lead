<?php

namespace Tests\Feature;

use App\Filament\Pages\Pipeline;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Team\Pages\ManageTeam;
use App\Filament\Resources\WaChannels\Pages\ListWaChannels;
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
        Livewire::test(Pipeline::class)->assertSeeHtml('class="jl-tabs"')->assertSeeHtml("'is-current': stage ===");
    }

    public function test_report_tables_label_each_figure_for_the_card_view(): void
    {
        Livewire::test(Reports::class)->assertSeeHtml('data-label="Konversi"');
    }
}
