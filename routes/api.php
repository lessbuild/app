<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ShowAccountController;
use Illuminate\Support\Facades\Route;

// Token API. Every route needs a token (auth:sanctum), resolves the token's account and checks scopes.
Route::prefix('v1')->middleware(['auth:sanctum', 'token.account', 'throttle:api'])->group(function (): void {
    Route::get('/account', ShowAccountController::class)->middleware('abilities:account:read')->name('api.v1.account');
});
