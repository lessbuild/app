<?php

declare(strict_types=1);

use App\Http\Controllers\Analytics\CollectEventsController;
use App\Http\Controllers\Analytics\PreflightCollectController;
use App\Http\Controllers\Api\V1\ShowAccountController;
use Illuminate\Support\Facades\Route;

// Token API. Every route needs a token (auth:sanctum), resolves the token's account and checks scopes.
Route::prefix('v1')->middleware(['auth:sanctum', 'token.account', 'throttle:api'])->group(function (): void {
    Route::get('/account', ShowAccountController::class)->middleware('abilities:account:read')->name('api.v1.account');
});

// Public contract from the old Analytics app: the tracker posts here without a token.
Route::post('/v1/collect/{publicId}', CollectEventsController::class)->middleware('throttle:collect')->where('publicId', '[A-Za-z0-9]+')->name('analytics.collect');
Route::options('/v1/collect/{publicId}', PreflightCollectController::class)->middleware('throttle:collect')->where('publicId', '[A-Za-z0-9]+');
