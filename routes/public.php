<?php

use App\Http\Controllers\Public\LandingPageController;
use Illuminate\Support\Facades\Route;

// Pages agencies publish for their own visitors. No session or cookies are
// started here; the form on them posts to the capture endpoint.
Route::middleware('throttle:public-pages')->group(function () {
    Route::get('p/{tenant}/{page}', [LandingPageController::class, 'show'])->name('landing.show');
    Route::get('p/{tenant}/{page}/pratinjau', [LandingPageController::class, 'preview'])->name('landing.preview')->middleware('signed');
    Route::get('f/{token}', [LandingPageController::class, 'form'])->name('form.show');
});

// Pricing, read from the same config/plans.php as the Langganan screen and the limit checks.
Route::get('harga', fn () => view('billing.pricing', [
    'plans' => config('plans.plans'), 'addons' => config('plans.addons'),
    'limitLabels' => config('plans.limit_labels'), 'featureLabels' => config('plans.feature_labels'), 'trialDays' => config('plans.trial_days'),
]))->middleware('throttle:public-pages')->name('pricing');
