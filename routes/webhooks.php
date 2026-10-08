<?php

use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Providers call these without a session or CSRF token; every request is
// authenticated by the per-channel token plus the provider's signature.
Route::middleware('throttle:webhooks')->prefix('webhooks/whatsapp')->group(function () {
    Route::get('official/{token}', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.official.verify');
    Route::post('official/{token}', [WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.official');
    Route::post('gateway/{token}', [WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.gateway');
});
