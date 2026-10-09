<?php

use App\Legal\LegalDocs;
use Illuminate\Support\Facades\Route;

// Front page, read from the same config/plans.php as /harga and the Langganan screen.
Route::get('/', fn () => view('landing', [
    'plans' => config('plans.plans'), 'addons' => config('plans.addons'),
    'limitLabels' => config('plans.limit_labels'), 'featureLabels' => config('plans.feature_labels'), 'trialDays' => config('plans.trial_days'),
]))->name('home');

// Privacy policy and terms of service (text in config/legal.php, editable in /admin).
Route::get('{slug}', fn (string $slug) => view('legal.show', ['doc' => LegalDocs::get($slug)]))
    ->whereIn('slug', LegalDocs::SLUGS)->middleware('throttle:public-pages')->name('legal');
