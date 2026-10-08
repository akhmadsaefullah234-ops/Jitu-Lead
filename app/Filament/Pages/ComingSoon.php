<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Menu entries for features that are planned and designed but not built yet,
 * so the team can see where they will live.
 */
abstract class ComingSoon extends Page
{
    protected string $view = 'filament.pages.coming-soon';

    /** @var list<string> */
    protected static array $points = [];

    public static function getNavigationBadge(): ?string
    {
        return 'Segera';
    }

    /** @return list<string> */
    public function points(): array
    {
        return static::$points;
    }
}
