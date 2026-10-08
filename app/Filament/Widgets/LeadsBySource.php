<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\VisibleLeads;
use App\Models\LeadSource;
use Filament\Widgets\ChartWidget;

class LeadsBySource extends ChartWidget
{
    use VisibleLeads;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Sumber lead';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    private const COLORS = ['#dc2626', '#f97316', '#facc15', '#16a34a', '#0ea5e9', '#6366f1', '#9ca3af'];

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = $this->leads()->selectRaw('lead_source_id, count(*) as total')->groupBy('lead_source_id')->orderByDesc('total')->get();
        $names = LeadSource::query()->pluck('name', 'id');

        return [
            'datasets' => [[
                'data' => $rows->pluck('total')->map(fn ($n) => (int) $n)->all(),
                'backgroundColor' => $rows->keys()->map(fn ($i) => self::COLORS[$i % count(self::COLORS)])->all(),
            ]],
            'labels' => $rows->map(fn ($r) => $names[$r->lead_source_id] ?? 'Tanpa sumber')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}
