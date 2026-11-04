<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Widgets\Concerns\UsesPagePeriod;
use App\Support\Industry;
use Filament\Widgets\Widget;

/** Per product: leads, Hot, orders, paid, done, sales value and conversion; a row opens the Lead list filtered. */
class ProductPerformance extends Widget
{
    use UsesPagePeriod;

    protected string $view = 'filament.widgets.dashboard.table';

    protected static ?int $sort = 5;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $period = $this->period();
        $retail = $this->data()->isRetail();

        return [
            'heading' => 'Per '.Industry::t('catalog_item'),
            'description' => 'Lead masuk pada periode ini, dan '.($retail ? 'order' : 'booking').' yang terjadi pada periode ini. Klik nama untuk melihat lead-nya.',
            'empty' => 'Belum ada lead pada periode ini.',
            'columns' => array_filter([
                'name' => [ucfirst(Industry::t('catalog_item')), false],
                'leads' => ['Lead', true],
                'hot' => ['Hot', true],
                'orders' => [$retail ? 'Order' : 'Booking', true],
                'paid' => [$retail ? 'Dibayar' : 'Closing', true],
                'done' => $retail ? ['Selesai', true] : null,
                'value' => ['Nilai', true],
                'rate' => ['Konversi', true],
            ]),
            'rows' => array_map(fn (array $r) => [
                ...$r,
                'value' => PeriodStats::rupiah($r['value']),
                'rate' => $r['rate'] === null ? '-' : $r['rate'].'%',
                'url' => LeadResource::getUrl('index', ['filters' => [
                    'produk' => ['value' => $r['id'] ?? 'none'],
                    'masuk' => $period->toQuery(),
                ]]),
            ], $this->data()->products($period)),
        ];
    }
}
