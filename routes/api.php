<?php

use App\Http\Controllers\Api\V1\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\PropertyPhotoController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantDocumentController;
use App\Http\Controllers\Api\V1\UnitController;
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

        Route::apiResource('properties.units', UnitController::class)->shallow();

        Route::apiResource('tenants', TenantController::class);
        Route::get('tenants/{tenant}/documents', [TenantDocumentController::class, 'index'])->name('tenants.documents.index');
        Route::post('tenants/{tenant}/documents', [TenantDocumentController::class, 'store'])->name('tenants.documents.store');
        Route::get('tenant-documents/{tenantDocument}/file', [TenantDocumentController::class, 'file'])->name('tenant-documents.file');
        Route::delete('tenant-documents/{tenantDocument}', [TenantDocumentController::class, 'destroy'])->name('tenant-documents.destroy');
    });
});
