<?php

namespace App\Providers;

use App\Ai\Transcriber;
use App\Ai\WhisperTranscriber;
use App\Support\CurrentTenant;
use App\Tutorials\CaptureFakes;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\BasePage;
use Filament\Tables\Table;
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
        $this->app->bind(Transcriber::class, WhisperTranscriber::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (CaptureFakes::enabled()) {
            CaptureFakes::register();
        }

        // Password::sendResetLink (e.g. the super admin's "send reset link" action) has no web route of its own: point it at the app panel's reset page.
        ResetPassword::createUrlUsing(fn ($user, string $token) => Filament::getPanel('app')->getResetPasswordUrl($token, $user));

        // On phones every table row becomes a card with its fields stacked, so no table needs sideways scrolling.
        // The filter button is labelled "Filter" (not a bare funnel), so search and filters read as one short row on phones.
        Table::configureUsing(fn (Table $table) => $table->stackedOnMobile()
            ->filtersTriggerAction(fn (Action $action) => $action->button()->label('Filter')));

        // Save and cancel stay in reach at the bottom of the screen while a long form is scrolled
        // (create and edit pages, settings pages); responsive.css lifts the bar above the phone tab bar.
        BasePage::stickyFormActions();

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->route('token') ?? $request->ip()));
        RateLimiter::for('ipaymu-notify', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public-pages', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('regions', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        RateLimiter::for('form-submit', fn (Request $request) => Limit::perMinute(10)->by($request->route('tenant').'|'.$request->route('form').'|'.$request->ip()));
        RateLimiter::for('capture', fn (Request $request) => Limit::perMinute(10)->by($request->route('token').'|'.$request->ip()));
    }
}
