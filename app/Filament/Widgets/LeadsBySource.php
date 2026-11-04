<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesPagePeriod;
use App\Reports\LeadOrigin;
use Filament\Widgets\ChartWidget;

/** Where the period's leads came from: WhatsApp from an ad, WhatsApp on its own, a form or landing page, the rest. */
class LeadsBySource extends ChartWidget
{
    use UsesPagePeriod;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Sumber lead';

    protected ?string $description = 'Lead yang masuk pada periode ini.';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    private const COLORS = [LeadOrigin::WA_ADS => '#dc2626', LeadOrigin::WA_ORGANIC => '#16a34a', LeadOrigin::FORM => '#0ea5e9', LeadOrigin::MANUAL => '#9ca3af'];

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = $this->data()->sources($this->period());

        return [
            'datasets' => [[
                'data' => array_values($counts),
                'backgroundColor' => array_values(array_intersect_key(self::COLORS, $counts)),
            ]],
            'labels' => array_map(fn ($key) => LeadOrigin::LABELS[$key].' ('.$counts[$key].')', array_keys($counts)),
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}
