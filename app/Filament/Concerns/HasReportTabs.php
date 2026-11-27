<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\AiSurveyReport;
use App\Filament\Pages\Reports;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;

use function Filament\Support\original_request;

/**
 * Laporan and "AI dan Survei" share tabs at the top of the page; the tabs show only to those who may open
 * "AI dan Survei" (property agencies, roles that see every lead). Both pages keep their own URL.
 */
trait HasReportTabs
{
    /** @return array<NavigationItem> */
    public function getSubNavigation(): array
    {
        if (Filament::getTenant() === null || ! AiSurveyReport::canAccess()) {
            return [];
        }

        return [
            NavigationItem::make('Ringkasan')->icon(Reports::getNavigationIcon())->sort(1)->url(Reports::getUrl())
                ->isActiveWhen(fn (): bool => original_request()->routeIs(Reports::getRouteName())),
            NavigationItem::make(AiSurveyReport::getNavigationLabel())->icon(AiSurveyReport::getNavigationIcon())->sort(2)->url(AiSurveyReport::getUrl())
                ->isActiveWhen(fn (): bool => original_request()->routeIs(AiSurveyReport::getRouteName())),
        ];
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }
}
