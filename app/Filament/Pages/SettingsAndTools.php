<?php

namespace App\Filament\Pages;

use App\Filament\Support\SettingsCatalog;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

use function Filament\Support\original_request;

/**
 * "Pengaturan & Alat": the last sidebar item, a page of grouped cards (App\Filament\Support\SettingsCatalog) for the
 * settings pages and tools that used to fill the AI Asisten, Pustaka media and Pengaturan groups of the sidebar.
 * The item stays lit on every page it holds, and shows the sum of their number badges.
 */
class SettingsAndTools extends Page
{
    protected string $view = 'filament.pages.settings-and-tools';

    protected static ?string $slug = 'pengaturan-alat';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Pengaturan & Alat';

    protected static ?string $title = 'Pengaturan & Alat';

    protected static ?int $navigationSort = 95;

    public const LABEL = 'Pengaturan & Alat';

    /** Hidden when nothing on it may be opened. */
    public static function canAccess(): bool
    {
        return SettingsCatalog::cards() !== [];
    }

    public static function getNavigationBadge(): ?string
    {
        $total = SettingsCatalog::badgeTotal();

        return $total === null ? null : (string) $total;
    }

    /** @return array<NavigationItem> */
    public static function getNavigationItems(): array
    {
        $patterns = [static::getRouteName(), ...SettingsCatalog::routePatterns()];

        return array_map(fn (NavigationItem $item) => $item->isActiveWhen(fn (): bool => original_request()->routeIs(...$patterns)), parent::getNavigationItems());
    }

    public function getViewData(): array
    {
        return [
            'groups' => SettingsCatalog::groups(),
            'quick' => SettingsCatalog::quick(),
        ];
    }
}
