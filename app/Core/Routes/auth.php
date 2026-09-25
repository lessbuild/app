<?php

use App\Core\Http\Controllers\Auth\PlatformAccountSecurityController;
use App\Core\Http\Controllers\Auth\PlatformEmailVerificationController;
use App\Core\Http\Controllers\Auth\PlatformPasskeyController;
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

Route::get('/', fn () => redirect()->route('platform.login'))->name('home');
Route::get('/login', [PlatformSessionController::class, 'create'])->name('login');
Route::post('/login', [PlatformSessionController::class, 'store'])
    ->middleware('throttle:platform.login')
    ->name('login.store');
Route::post('/passkeys/login/options', [PlatformPasskeyController::class, 'loginOptions'])
    ->middleware('throttle:platform.passkey')
    ->name('passkey.login.options');
Route::post('/passkeys/login', [PlatformPasskeyController::class, 'login'])
    ->middleware('throttle:platform.passkey')
    ->name('passkey.login');

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

Route::middleware('auth:platform')->group(function (): void {
    Route::get('/account/security', [PlatformAccountSecurityController::class, 'index'])
        ->name('account.security');
    Route::post('/account/security/passkeys/options', [PlatformPasskeyController::class, 'registrationOptions'])
        ->middleware(['throttle:sensitive-account', 'throttle:platform.passkey'])
        ->name('account.passkeys.options');
    Route::post('/account/security/passkeys', [PlatformPasskeyController::class, 'store'])
        ->middleware(['platform.passkey-registration-confirmed', 'throttle:platform.passkey'])
        ->name('account.passkeys.store');
    Route::delete('/account/security/passkeys/{passkeyId}', [PlatformPasskeyController::class, 'destroy'])
        ->where('passkeyId', '[0-9A-HJKMNP-TV-Z]{26}')
        ->middleware(['throttle:sensitive-account', 'throttle:platform.passkey'])
        ->name('account.passkeys.destroy');
    Route::post('/account/security/password', [PlatformAccountSecurityController::class, 'updatePassword'])
        ->middleware('throttle:sensitive-account')
        ->name('account.password.update');
    Route::post('/account/security/two-factor', [PlatformAccountSecurityController::class, 'beginTwoFactor'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.begin');
    Route::post('/account/security/two-factor/confirm', [PlatformAccountSecurityController::class, 'confirmTwoFactor'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.confirm');
    Route::post('/account/security/two-factor/cancel', [PlatformAccountSecurityController::class, 'cancelTwoFactorSetup'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.cancel');
    Route::delete('/account/security/two-factor', [PlatformAccountSecurityController::class, 'disableTwoFactor'])
        ->middleware('throttle:sensitive-account')
        ->name('account.two-factor.disable');
    Route::post('/account/security/recovery-codes', [PlatformAccountSecurityController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:sensitive-account')
        ->name('account.recovery-codes.regenerate');
    Route::post('/account/security/sessions/revoke-others', [PlatformAccountSecurityController::class, 'revokeOtherSessions'])
        ->middleware('throttle:sensitive-account')
        ->name('account.sessions.revoke-others');
    Route::delete('/account/security/sessions/{session}', [PlatformAccountSecurityController::class, 'revokeSession'])
        ->where('session', '[0-9A-HJKMNP-TV-Z]{26}')
        ->middleware('throttle:sensitive-account')
        ->name('account.sessions.revoke');
});
