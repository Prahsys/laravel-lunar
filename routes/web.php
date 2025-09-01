<?php

use Illuminate\Support\Facades\Route;
use Prahsys\Lunar\Http\Controllers\CheckoutController;
use Prahsys\Lunar\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Prahsys Lunar Web Routes
|--------------------------------------------------------------------------
|
| Here are the web routes for the Prahsys Lunar package.
| These routes are loaded by the LunarPrahsysServiceProvider.
|
*/

Route::group([
    'prefix' => 'prahsys',
    'as' => 'prahsys.',
], function () {
    // Checkout routes
    Route::group([
        'prefix' => 'checkout',
        'as' => 'checkout.',
    ], function () {
        Route::post('/', [CheckoutController::class, 'create'])->name('create');
        Route::get('/success', [CheckoutController::class, 'success'])->name('success');
        Route::get('/cancel', [CheckoutController::class, 'cancel'])->name('cancel');
        Route::get('/status/{sessionId}', [CheckoutController::class, 'status'])->name('status');
    });
    
    // Webhook routes
    Route::group([
        'prefix' => 'webhooks',
        'as' => 'webhooks.',
        'middleware' => config('lunar-prahsys.webhooks.middleware', ['api']),
    ], function () {
        // Dedicated Lunar webhook handler (recommended)
        Route::post('/lunar', [WebhookController::class, 'handle'])->name('lunar');
        Route::get('/status', [WebhookController::class, 'status'])->name('status');
        
        // Legacy webhook route (for backward compatibility)
        Route::post('/lunar-legacy', [CheckoutController::class, 'webhook'])->name('lunar-legacy');
    });
});