<?php

namespace App\Filament\Widgets;

use App\Models\Banner;
use Filament\Widgets\Widget;

/** The sliding banner strip the super admin sets, shown only while at least one banner is live. */
class BannerSlider extends Widget
{
    protected string $view = 'filament.widgets.banner-slider';

    protected static ?int $sort = -20;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Banner::query()->live()->exists();
    }

    public function getViewData(): array
    {
        return ['banners' => Banner::query()->live()->get()];
    }
}
