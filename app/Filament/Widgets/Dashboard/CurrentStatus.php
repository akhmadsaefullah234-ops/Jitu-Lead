<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\Tasks;
use App\Filament\Resources\Leads\LeadResource;
use App\Reports\DashboardData;
use Filament\Widgets\Widget;

/** Right now, whatever the period: each WhatsApp number, late tasks, and leads nobody has answered yet. */
class CurrentStatus extends Widget
{
    protected string $view = 'filament.widgets.dashboard.status';

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return app(DashboardData::class)->status() + [
            'overdueUrl' => LeadResource::getUrl('index', ['filters' => ['overdue' => ['isActive' => true]]]),
            'unattendedUrl' => LeadResource::getUrl('index', ['filters' => ['unattended' => ['isActive' => true]]]),
            'tasksUrl' => class_exists(Tasks::class) ? Tasks::getUrl() : null,
        ];
    }
}
