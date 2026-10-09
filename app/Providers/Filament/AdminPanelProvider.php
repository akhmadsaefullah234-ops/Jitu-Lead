<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Dashboard;
use App\Http\Middleware\NoCurrentTenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The platform owner's panel (/admin): every agency, plan, payment request,
 * invite code and support chat in one place. Only users flagged is_super_admin
 * get in (see User::canAccessPanel); it has no tenant, so it sees all agencies.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->brandName('JITU LEAD Admin')
            ->login()
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/icon.png'))
            ->font('Inter')
            ->darkMode(false)
            ->maxContentWidth('full')
            ->colors([
                'primary' => Color::Red,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Green,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.theme'))
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->middleware([NoCurrentTenant::class], isPersistent: true);
    }
}
