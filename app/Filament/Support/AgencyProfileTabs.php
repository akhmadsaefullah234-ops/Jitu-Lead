<?php

namespace App\Filament\Support;

use App\Billing\TrialLock;
use App\Filament\Pages\AgencyProfilePage;
use App\Filament\Pages\DailyReports;
use App\Filament\Pages\DataPrivacy;
use App\Filament\Pages\Notifications;
use App\Filament\Pages\Subscription;
use App\Filament\Resources\Team\TeamResource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;

use function Filament\Support\original_request;

/**
 * "Profil agensi": the agency's own settings, opened from the account menu (top right) and shown as tabs at the
 * top of each of these pages (App\Filament\Concerns\HasAgencyProfileTabs). Each page keeps its URL, access rules
 * and contents; a tab shows only when its canAccess() lets the person in.
 */
final class AgencyProfileTabs
{
    public const LABEL = 'Profil agensi';

    /** @return array<class-string, string> page or resource => tab label, in order */
    public static function definitions(): array
    {
        return [
            AgencyProfilePage::class => 'Profil',
            TeamResource::class => 'Tim',
            Subscription::class => 'Langganan',
            Notifications::class => 'Notifikasi',
            DailyReports::class => 'Laporan harian',
            DataPrivacy::class => 'Data & privasi',
        ];
    }

    /** @return list<class-string> */
    public static function members(): array
    {
        return array_keys(self::definitions());
    }

    /** @return array<class-string, string> the tabs this person may open */
    public static function visible(): array
    {
        if (Filament::getTenant() === null || TrialLock::applies()) {
            return [];
        }

        return array_filter(self::definitions(), fn (string $label, string $class) => $class::canAccess(), ARRAY_FILTER_USE_BOTH);
    }

    /** The first tab this person may open, for the account menu item; null hides the item. */
    public static function firstUrl(): ?string
    {
        $class = array_key_first(self::visible());

        return $class === null ? null : self::url($class);
    }

    public static function url(string $class): string
    {
        return is_subclass_of($class, Resource::class) ? $class::getUrl('index') : $class::getUrl();
    }

    /** @return array<NavigationItem> */
    public static function items(): array
    {
        $items = [];
        $sort = 0;

        foreach (self::visible() as $class => $label) {
            $pattern = $class::getNavigationItemActiveRoutePattern();
            $badge = $class::getNavigationBadge();

            $items[] = NavigationItem::make($label)
                ->icon($class::getNavigationIcon())
                ->sort($sort++)
                ->url(self::url($class))
                ->badge($badge, color: filled($badge) ? $class::getNavigationBadgeColor() : null)
                ->isActiveWhen(fn (): bool => original_request()->routeIs($pattern));
        }

        return $items;
    }
}
