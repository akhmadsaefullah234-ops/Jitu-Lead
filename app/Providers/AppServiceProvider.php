<?php

namespace App\Providers;

use App\Support\CurrentTenant;
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
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->route('token') ?? $request->ip()));
        RateLimiter::for('public-pages', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('capture', fn (Request $request) => Limit::perMinute(10)->by($request->route('token').'|'.$request->ip()));
    }
}
