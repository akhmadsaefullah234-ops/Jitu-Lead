<?php

namespace App\Filament\Widgets\Concerns;

use App\Reports\DashboardData;
use App\Support\Period;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** A Dashboard widget that follows the period picked at the top of the page. */
trait UsesPagePeriod
{
    use InteractsWithPageFilters;

    protected function period(): Period
    {
        $f = (array) $this->pageFilters;

        return Period::make($f['periode'] ?? null, $f['dari'] ?? null, $f['sampai'] ?? null);
    }

    protected function data(): DashboardData
    {
        return app(DashboardData::class);
    }
}
