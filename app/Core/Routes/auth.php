<?php

use App\Core\Http\Controllers\Auth\PlatformEmailVerificationController;
use App\Core\Http\Controllers\Auth\PlatformPasswordResetController;
use App\Core\Http\Controllers\Auth\PlatformRegistrationController;
use App\Core\Http\Controllers\Auth\PlatformSessionController;
use App\Core\Http\Controllers\Auth\PlatformWorkspaceInvitationController;

Route::get('/invitations/{token}', [PlatformWorkspaceInvitationController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->name('workspace-invitations.show');
Route::post('/invitations/{token}/accept', [PlatformWorkspaceInvitationController::class, 'accept'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('auth:platform')
    ->name('workspace-invitations.accept');

Route::get('/login', [PlatformSessionController::class, 'create'])->name('login');
Route::post('/login', [PlatformSessionController::class, 'store'])
    ->middleware('throttle:platform.login')
    ->name('login.store');

Route::get('/register', [PlatformRegistrationController::class, 'create'])->name('register');
Route::post('/register', [PlatformRegistrationController::class, 'store'])
    ->middleware('throttle:platform.register')
    ->name('register.store');

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

Route::get('/email/verify', [PlatformEmailVerificationController::class, 'notice'])
    ->middleware('auth:platform')
    ->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', [PlatformEmailVerificationController::class, 'verify'])
    ->middleware(['auth:platform', 'signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::post('/email/verification-notification', [PlatformEmailVerificationController::class, 'resend'])
    ->middleware(['auth:platform', 'throttle:6,1'])
    ->name('verification.send');

Route::post('/logout', [PlatformSessionController::class, 'destroy'])
    ->middleware('auth:platform')
    ->name('logout');
