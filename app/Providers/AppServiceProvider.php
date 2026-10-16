<?php

namespace App\Providers;

use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password::sendResetLink (e.g. the super admin's "send reset link" action) has no web route of its own: point it at the app panel's reset page.
        ResetPassword::createUrlUsing(fn ($user, string $token) => Filament::getPanel('app')->getResetPasswordUrl($token, $user));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->route('token') ?? $request->ip()));
        RateLimiter::for('ipaymu-notify', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public-pages', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('capture', fn (Request $request) => Limit::perMinute(10)->by($request->route('token').'|'.$request->ip()));
    }
}
