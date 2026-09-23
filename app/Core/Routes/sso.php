<?php

use App\Core\Http\Controllers\Auth\PlatformSsoController;

Route::get('__platform/sso/issue', [PlatformSsoController::class, 'issue'])
    ->middleware(['auth:platform', 'throttle:platform.sso.issue']);

Route::post('__platform/sso/exchange', [PlatformSsoController::class, 'exchange'])
    ->middleware('throttle:platform.sso.exchange');
