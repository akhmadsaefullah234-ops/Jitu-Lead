<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\SettingsAndTools;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page as ResourcePage;

/**
 * For the pages opened from "Pengaturan & Alat" (App\Filament\Support\SettingsCatalog): a breadcrumb back to it.
 * A resource page keeps its own breadcrumbs after it; a plain page ends with its menu name.
 */
trait InSettingsAndTools
{
    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        $own = array_filter(parent::getBreadcrumbs(), fn ($crumb) => filled($crumb));

        if (Filament::getTenant() === null) {
            return $own;
        }

        // A list page of a resource has no breadcrumbs of its own: it ends with the resource's menu name.
        $label = $this instanceof ResourcePage ? static::getResource()::getNavigationLabel() : static::getNavigationLabel();

        return [SettingsAndTools::getUrl() => SettingsAndTools::LABEL, ...($own === [] ? [$label] : $own)];
    }
}
