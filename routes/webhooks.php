<?php

use App\Http\Controllers\Webhooks\IpaymuNotifyController;
use App\Http\Controllers\Webhooks\LeadCaptureController;
use App\Http\Controllers\Webhooks\ShippingCallbackController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Providers call these without a session or CSRF token; every request is
// authenticated by the per-channel token plus the provider's signature.
Route::middleware('throttle:webhooks')->prefix('webhooks/whatsapp')->group(function () {
    Route::get('official/{token}', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.official.verify');
    Route::post('official/{token}', [WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.official');
    Route::post('gateway/{token}', [WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.gateway');
});

// Website forms post here from any origin; no cookies are involved, and the
// token plus a per-visitor rate limit keep it from being flooded.
Route::middleware('throttle:capture')->prefix('capture')->group(function () {
    Route::post('{token}', [LeadCaptureController::class, 'store'])->name('capture.store');
    Route::options('{token}', [LeadCaptureController::class, 'preflight']);
});

// iPaymu payment notification: no session or CSRF token, authenticated by its signature and a second check with iPaymu.
Route::post('billing/ipaymu/notify', IpaymuNotifyController::class)->middleware('throttle:ipaymu-notify')->name('billing.ipaymu.notify');

// Courier aggregators (Everpro): tracking number issued and parcel status changes. The token is the agency's
// own (Pengaturan > Pengiriman); the provider's key header is checked against that agency's callback key.
Route::middleware('throttle:webhooks')->post('webhooks/shipping/{provider}/{token}', ShippingCallbackController::class)
    ->where(['provider' => '[a-z]+', 'token' => '[A-Za-z0-9]{20,64}'])->name('webhooks.shipping');
