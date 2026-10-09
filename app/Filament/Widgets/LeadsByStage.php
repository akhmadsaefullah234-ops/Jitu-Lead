<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\VisibleLeads;
use App\Models\Stage;
use Filament\Widgets\ChartWidget;

class LeadsByStage extends ChartWidget
{
    use VisibleLeads;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Lead per tahap';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $stages = Stage::query()->orderBy('position')->get();
        $counts = $this->leads()->selectRaw('stage_id, count(*) as total')->groupBy('stage_id')->pluck('total', 'stage_id');

        return [
            'datasets' => [[
                'label' => 'Lead',
                'data' => $stages->map(fn (Stage $s) => (int) ($counts[$s->getKey()] ?? 0))->all(),
                'backgroundColor' => $stages->map(fn (Stage $s) => match ($s->type->value) {
                    'won' => '#16a34a', 'lost' => '#9ca3af', default => '#dc2626',
                })->all(),
                'borderRadius' => 6,
            ]],
            'labels' => $stages->pluck('name')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
