<?php

use App\Http\Controllers\Api\V1\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Middleware\EnsureUserCanSignIn;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserCanSignIn::class])->group(function (): void {
        Route::post('auth/logout', [AuthenticatedSessionController::class, 'destroy'])->name('auth.logout');
        Route::get('me', CurrentUserController::class)->name('me');
    });
});
