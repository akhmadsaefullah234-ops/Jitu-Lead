<?php

namespace App\Filament\Concerns;

use App\Filament\Support\AgencyProfileTabs;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * The "Profil agensi" tabs (App\Filament\Support\AgencyProfileTabs) at the top of each of its pages. On phones
 * Filament shows them as one drop-down button, so they never push the page sideways.
 */
trait HasAgencyProfileTabs
{
    /** @return array<NavigationItem> */
    public function getSubNavigation(): array
    {
        return AgencyProfileTabs::items();
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }
}
