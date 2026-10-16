<?php

use App\Http\Controllers\EmailVerificationHelpController;
use App\Http\Controllers\TrialEmailUnsubscribeController;
use App\Http\Controllers\WhatsAppMediaController;
use App\Legal\LegalDocs;
use App\Marketing\Faq;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Route;

// Front page, read from the same config/plans.php as /harga and the Langganan screen.
Route::get('/', fn () => view('landing', [
    'plans' => config('plans.plans'), 'addons' => config('plans.addons'),
    'limitLabels' => config('plans.limit_labels'), 'featureLabels' => config('plans.feature_labels'), 'trialDays' => config('plans.trial_days'),
    'testimonials' => config('landing.testimonials', []),
    'ownerContact' => PlatformSetting::publicContact(),
    'faq' => Faq::items(),
]))->name('home');

// Public information pages, in the same style as the legal pages: questions and answers (the same list as the front page) and the business contact.
Route::middleware('auth')->group(function () {
    Route::post('verifikasi-email/ganti-akun', [EmailVerificationHelpController::class, 'switchAccount'])->middleware('throttle:10,1')->name('verification.switch');
    Route::post('verifikasi-email/kirim-ulang', [EmailVerificationHelpController::class, 'resend'])->middleware('throttle:3,1')->name('verification.resend');
});

Route::get('faq', fn () => view('legal.faq', ['faq' => Faq::items()]))->middleware('throttle:public-pages')->name('faq');
Route::get('kontak', fn () => view('legal.contact', ['company' => config('legal.company'), 'contact' => PlatformSetting::fullContact()]))
    ->middleware('throttle:public-pages')->name('contact');

// Privacy policy, terms of service and refund policy (text in config/legal.php, editable in /admin).
Route::get('{slug}', fn (string $slug) => view('legal.show', ['doc' => LegalDocs::get($slug)]))
    ->whereIn('slug', LegalDocs::SLUGS)->middleware('throttle:public-pages')->name('legal');

// "Berhenti menerima email ini" in the trial emails: a signed link per user. The POST is also the one-click
// unsubscribe mail apps send (List-Unsubscribe-Post), so it is exempt from the CSRF check in bootstrap/app.php.
Route::middleware(['signed', 'throttle:public-pages'])->group(function () {
    Route::get('email/berhenti/{user}', [TrialEmailUnsubscribeController::class, 'show'])->whereNumber('user')->name('trial-emails.unsubscribe');
    Route::post('email/berhenti/{user}', [TrialEmailUnsubscribeController::class, 'store'])->whereNumber('user');
});

// Pictures customers send in WhatsApp chats. Private files, opened only by people who may see the lead.
Route::get('wa-media/{message}', [WhatsAppMediaController::class, 'show'])
    ->whereNumber('message')->name('wa.media');
