<?php

use App\Core\Http\Controllers\Auth\PlatformPasswordResetController;
use App\Core\Http\Controllers\Auth\PlatformSessionController;

Route::get('/login', [PlatformSessionController::class, 'create'])->name('login');
Route::post('/login', [PlatformSessionController::class, 'store'])
    ->middleware('throttle:platform.login')
    ->name('login.store');

Route::get('/two-factor-challenge', [PlatformSessionController::class, 'createTwoFactorChallenge'])
    ->name('two-factor.create');
Route::post('/two-factor-challenge', [PlatformSessionController::class, 'storeTwoFactorChallenge'])
    ->middleware('throttle:sensitive-account')
    ->name('two-factor.store');

Route::get('/forgot-password', [PlatformPasswordResetController::class, 'create'])->name('password.request');
Route::post('/forgot-password', [PlatformPasswordResetController::class, 'send'])
    ->middleware('throttle:sensitive-account')
    ->name('password.email');
Route::get('/reset-password/{token}', [PlatformPasswordResetController::class, 'edit'])->name('password.reset');
Route::post('/reset-password', [PlatformPasswordResetController::class, 'update'])
    ->middleware('throttle:sensitive-account')
    ->name('password.update');

Route::post('/logout', [PlatformSessionController::class, 'destroy'])
    ->middleware('auth:platform')
    ->name('logout');
