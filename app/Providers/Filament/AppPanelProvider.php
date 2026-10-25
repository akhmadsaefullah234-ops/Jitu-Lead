<?php

namespace App\Providers\Filament;

use App\Billing\TrialLock;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\RegisterAgency;
use App\Http\Middleware\LockExpiredTrial;
use App\Http\Middleware\SetCurrentTenant;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->brandName('JITU LEAD')
            ->login()
            ->registration(config('jitu.registration') === 'closed' ? null : Register::class)
            ->passwordReset()
            ->emailVerification()
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterAgency::class)
            ->tenantMiddleware([SetCurrentTenant::class], isPersistent: true)
            ->tenantMiddleware([LockExpiredTrial::class])
            // An agency locked after its trial sees only the page that explains it and lets it renew.
            ->navigation(fn (NavigationBuilder $builder) => TrialLock::applies()
                ? $builder->items(TrialLock::openPages()[0]::getNavigationItems())
                : true)
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/icon.png'))
            ->font('Inter')
            ->darkMode(false)
            ->maxContentWidth('full')
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Red,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Green,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.theme').view('filament.pwa-head'))
            ->renderHook(PanelsRenderHook::BODY_START, fn () => app()->environment('staging') ? view('filament.staging-banner') : '')
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER, fn () => view('filament.legal-links', ['register' => true]))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.legal-links'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => Filament::getTenant() ? view('filament.plan-badge') : '')
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => Filament::getTenant() && auth()->check() ? view('filament.support-menu-item') : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn () => Filament::getTenant() && auth()->check() && ! TrialLock::applies() ? view('filament.mobile-nav') : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn () => Filament::getTenant() && auth()->check() && ! TrialLock::applies() ? Blade::render('@livewire(\\App\\Livewire\\PlanPopup::class)') : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn () => Filament::getTenant() && auth()->check() ? Blade::render('@livewire(\\App\\Livewire\\SupportChat::class)') : '')
            // Nothing to search in a locked agency
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Filament::getTenant() && TrialLock::applies() ? new HtmlString('<style>.fi-global-search { display: none; }</style>') : '')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
            ]);
    }
}
