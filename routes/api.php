<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MobileCommandController;
use App\Http\Controllers\Api\MobilePasswordResetController;
use App\Http\Controllers\Api\MobileSessionController;
use App\Http\Controllers\Api\MobileStateController;
use App\Http\Controllers\FormIntegrationController;
use App\Http\Middleware\AuthenticateForms;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventCaching;
use App\Http\Middleware\SetDatabaseActor;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/integrations/forms')->middleware(['throttle:60,1,forms-webhook', AuthenticateForms::class])->group(function (): void {
    Route::post('/events', [FormIntegrationController::class, 'store'])->name('forms.events');
    Route::get('/appointments/{integrationId}', [FormIntegrationController::class, 'show'])
        ->whereUuid('integrationId')->name('forms.appointment');
});

Route::prefix('v1')->middleware(PreventCaching::class)->group(function (): void {
    Route::middleware('throttle:20,1,api-sign-in')->group(function (): void {
        Route::post('/login', [MobileSessionController::class, 'store'])->name('api.login');
        Route::post('/forgot-password', MobilePasswordResetController::class)->name('api.password.email');
    });

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class, SetDatabaseActor::class])->group(function (): void {
        Route::post('/logout', [MobileSessionController::class, 'destroy'])->name('api.logout');
        Route::get('/mobile/bootstrap', MobileStateController::class)->middleware('throttle:60,1,api-bootstrap')->name('api.mobile.bootstrap');
        Route::post('/mobile/commands', MobileCommandController::class)->middleware('throttle:120,1,api-commands')->name('api.mobile.commands');
    });
});
