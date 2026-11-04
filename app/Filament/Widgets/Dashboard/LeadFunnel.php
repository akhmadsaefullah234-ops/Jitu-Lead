<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Concerns\UsesPagePeriod;
use Filament\Widgets\Widget;

/** How far the leads that came in during the period got, stage by stage. */
class LeadFunnel extends Widget
{
    use UsesPagePeriod;

    protected string $view = 'filament.widgets.dashboard.funnel';

    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $period = $this->period();
        $stages = $this->data()->funnel($period);

        return ['stages' => $stages, 'top' => max(1, $stages[0]['count'] ?? 0), 'lost' => $this->data()->lostOf($period)];
    }
}
