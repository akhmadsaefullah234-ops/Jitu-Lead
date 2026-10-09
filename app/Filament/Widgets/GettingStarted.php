<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Models\Lead;
use App\Support\CurrentTenant;
use Filament\Widgets\Widget;

/**
 * Shown only while the agency has no leads, so the first screen is a guide
 * instead of an empty page.
 */
class GettingStarted extends Widget
{
    protected string $view = 'filament.widgets.getting-started';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ! Lead::query()->exists();
    }

    public function isAdmin(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }
}
