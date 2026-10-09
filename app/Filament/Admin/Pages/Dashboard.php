<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\PlatformOverview;
use App\Filament\Admin\Widgets\SignupsChart;
use App\Filament\Admin\Widgets\TrialsEnding;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dasbor';

    protected static ?string $title = 'Dasbor Super Admin';

    protected static ?int $navigationSort = 0;

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [PlatformOverview::class, SignupsChart::class, TrialsEnding::class];
    }
}
