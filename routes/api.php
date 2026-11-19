<?php

use App\Http\Controllers\Api\V1\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\PropertyPhotoController;
use App\Http\Middleware\EnsureUserCanSignIn;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserCanSignIn::class])->group(function (): void {
        Route::post('auth/logout', [AuthenticatedSessionController::class, 'destroy'])->name('auth.logout');
        Route::get('me', CurrentUserController::class)->name('me');
        Route::get('lookups', LookupController::class)->name('lookups');

        Route::apiResource('properties', PropertyController::class);
        Route::post('properties/{property}/photo', [PropertyPhotoController::class, 'store'])->name('properties.photo.store');
        Route::delete('properties/{property}/photo', [PropertyPhotoController::class, 'destroy'])->name('properties.photo.destroy');
    });
});
