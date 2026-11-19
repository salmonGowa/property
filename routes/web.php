<?php

use App\Http\Controllers\MpesaCallbackController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * The React back office. Every path under /app is handled by the SPA's own router;
 * data comes from the /api/v1 endpoints.
 */
Route::view('/app/{path?}', 'spa')->where('path', '.*')->name('spa');

Route::prefix('mpesa/{token}/c2b')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:120,1')
    ->name('mpesa.c2b.')
    ->group(function (): void {
        Route::post('validation', [MpesaCallbackController::class, 'validation'])->name('validation');
        Route::post('confirmation', [MpesaCallbackController::class, 'confirmation'])->name('confirmation');
    });
