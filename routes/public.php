<?php

use App\Http\Controllers\Public\LandingPageController;
use App\Http\Controllers\Public\LeadFormController;
use App\Http\Controllers\Public\RegionController;
use Illuminate\Support\Facades\Route;

// Pages and forms agencies publish for their own visitors. No session or cookies are
// started here; built forms post back to their own address.
Route::middleware('throttle:public-pages')->group(function () {
    Route::get('p/{tenant}/{page}', [LandingPageController::class, 'show'])->name('landing.show');
    Route::get('p/{tenant}/{page}/pratinjau', [LandingPageController::class, 'preview'])->name('landing.preview')->middleware('signed');
    Route::get('f/{token}', [LeadFormController::class, 'legacy'])->name('form.show');
    Route::get('f/{tenant}/{form}', [LeadFormController::class, 'show'])->name('form.page');
    Route::get('f/{tenant}/{form}/pratinjau', [LeadFormController::class, 'preview'])->name('form.preview')->middleware('signed');
    Route::get('w/{tenant}', [LeadFormController::class, 'whatsapp'])->name('go.wa');
});

// Address dropdowns: the regions under a Kemendagri code, one level at a time (or every city at once).
Route::get('wilayah/semua-kota', [RegionController::class, 'cities'])->middleware('throttle:regions')->name('regions.cities');
Route::get('wilayah/{code?}', [RegionController::class, 'children'])->where('code', '\d{2}(\.\d{2}){0,2}')
    ->middleware('throttle:regions')->name('regions.children');

Route::post('f/{tenant}/{form}', [LeadFormController::class, 'submit'])->middleware('throttle:form-submit')->name('form.submit');
Route::get('f/{tenant}/{form}/ringkasan', [LeadFormController::class, 'quote'])->middleware('throttle:regions')->name('form.quote');

// Pricing, read from the same config/plans.php as the Langganan screen and the limit checks.
Route::get('harga', fn () => view('billing.pricing', [
    'plans' => config('plans.plans'), 'addons' => config('plans.addons'),
    'limitLabels' => config('plans.limit_labels'), 'featureLabels' => config('plans.feature_labels'), 'trialDays' => config('plans.trial_days'),
]))->middleware('throttle:public-pages')->name('pricing');
