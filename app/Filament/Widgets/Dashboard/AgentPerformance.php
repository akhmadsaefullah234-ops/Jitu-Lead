<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Concerns\UsesPagePeriod;
use App\Support\CurrentTenant;
use Filament\Widgets\Widget;

/** Per agent, for admins and team leaders of a team of two or more. */
class AgentPerformance extends Widget
{
    use UsesPagePeriod;

    protected string $view = 'filament.widgets.dashboard.table';

    protected static ?int $sort = 8;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public static function canView(): bool
    {
        $current = app(CurrentTenant::class);

        return ($current->roleOf(auth()->user())?->seesAllLeads() ?? false) && $current->get()?->activeUsers()->count() > 1;
    }

    protected function getViewData(): array
    {
        $retail = $this->data()->isRetail();

        return [
            'heading' => 'Per agen',
            'description' => 'Lead yang masuk ke tiap agen dan hasilnya pada periode ini.',
            'empty' => 'Belum ada lead pada periode ini.',
            'columns' => [
                'name' => ['Agen', false],
                'leads' => ['Lead', true],
                'orders' => [$retail ? 'Order' : 'Booking', true],
                'won' => [$retail ? 'Selesai' : 'Closing', true],
                'response' => ['Balas pertama', true],
            ],
            'rows' => array_map(fn (array $r) => [...$r, 'response' => PeriodStats::minutes($r['response'])], $this->data()->agents($this->period())),
        ];
    }
}
