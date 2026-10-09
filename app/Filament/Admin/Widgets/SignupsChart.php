<?php

namespace App\Filament\Admin\Widgets;

use App\Billing\PlatformStats;
use Filament\Widgets\ChartWidget;

class SignupsChart extends ChartWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Pendaftar baru, 30 hari terakhir';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $series = PlatformStats::signupsPerDay(30);

        return [
            'datasets' => [['label' => 'Agensi baru', 'data' => array_values($series), 'borderColor' => '#dc2626', 'backgroundColor' => 'rgba(220,38,38,.15)', 'fill' => true]],
            'labels' => array_map(fn ($d) => substr($d, 5), array_keys($series)),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
