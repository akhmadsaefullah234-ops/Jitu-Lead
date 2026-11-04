<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Widgets\Concerns\UsesPagePeriod;
use Filament\Widgets\Widget;

/** The 10 ads that brought the most leads in the period, and every lead without ad data as one row. */
class AdPerformance extends Widget
{
    use UsesPagePeriod;

    protected string $view = 'filament.widgets.dashboard.table';

    protected static ?int $sort = 6;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $period = $this->period();
        $retail = $this->data()->isRetail();

        return [
            'heading' => 'Per iklan',
            'description' => '10 iklan dengan lead terbanyak (judul atau ID iklan dari Click to WhatsApp).',
            'empty' => 'Belum ada lead pada periode ini.',
            'columns' => [
                'name' => ['Iklan', false],
                'leads' => ['Lead', true],
                'orders' => [$retail ? 'Order' : 'Booking', true],
                'paid' => [$retail ? 'Dibayar' : 'Closing', true],
                'value' => ['Nilai', true],
            ],
            'rows' => array_map(fn (array $r) => [
                ...$r,
                'value' => PeriodStats::rupiah($r['value']),
                'url' => $r['ad'] !== null ? LeadResource::getUrl('index', ['filters' => ['iklan' => ['value' => $r['ad']], 'masuk' => $period->toQuery()]]) : null,
            ], $this->data()->ads($period)),
        ];
    }
}
