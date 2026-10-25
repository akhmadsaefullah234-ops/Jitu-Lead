<?php

namespace App\Filament\Pages;

use App\Billing\TrialLock;
use App\Enums\Role;
use App\Support\CurrentTenant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** What a member of an agency locked after its trial sees (TrialLock): why, what is kept, and who can renew. */
class TrialEnded extends Page
{
    protected string $view = 'filament.pages.trial-ended';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $navigationLabel = 'Masa percobaan berakhir';

    protected static ?string $title = 'Masa percobaan berakhir';

    protected static ?string $slug = 'masa-percobaan-berakhir';

    public static function shouldRegisterNavigation(): bool
    {
        return false; // Only reached through TrialLock, which builds the menu itself.
    }

    public function isAdmin(): bool
    {
        return app(CurrentTenant::class)->roleOf(auth()->user()) === Role::Admin;
    }

    /** @return Collection<int, string> */
    public function adminNames(): Collection
    {
        return app(CurrentTenant::class)->get()?->activeUsers()->wherePivot('role', Role::Admin->value)->orderBy('name')->pluck('name') ?? collect();
    }

    public function getViewData(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        return [
            'tenant' => $tenant,
            'locked' => TrialLock::applies($tenant),
            'endedAt' => $tenant?->currentSubscription()->trial_ends_at?->timezone(config('app.timezone')),
        ];
    }
}
