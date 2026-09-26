<?php

use App\Core\Http\Controllers\Auth\PlatformSessionController;
use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Deployer\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Modules\Deployer\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Modules\Deployer\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Modules\Deployer\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Modules\Deployer\Http\Controllers\Auth\NewPasswordController;
use App\Modules\Deployer\Http\Controllers\Auth\PasswordResetLinkController;
use App\Modules\Deployer\Http\Controllers\Auth\RegisteredUserController;
use App\Modules\Deployer\Http\Controllers\Auth\SocialAuthController;
use App\Modules\Deployer\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Modules\Deployer\Http\Controllers\Auth\VerifyEmailController;
use App\Modules\Deployer\Http\Middleware\EnforceOrganizationSecurity;
use App\Modules\Deployer\Http\Middleware\EnsureCurrentOrganization;
use Illuminate\Support\Facades\Route;

$deployerAuthentication = app(ProductAuthentication::class);
$guestMiddleware = $deployerAuthentication->guestMiddleware('deployer');
$authenticatedMiddleware = [
    ...$deployerAuthentication->authenticatedMiddleware('deployer'),
    EnsureCurrentOrganization::class,
    EnforceOrganizationSecurity::class,
];
$logoutAction = $deployerAuthentication->usesCoreAuthority('deployer')
    ? [PlatformSessionController::class, 'destroy']
    : [AuthenticatedSessionController::class, 'destroy'];
$socialCallbackMiddleware = $deployerAuthentication->usesCoreAuthority('deployer') ? $guestMiddleware : [];

Route::middleware($guestMiddleware)->group(function (): void {
    Route::get('auth/social/redirect/{provider}', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', SocialAuthController::providers())
        ->name('social.login');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:sensitive-account')
        ->name('two-factor.login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:sensitive-account')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:sensitive-account')
        ->name('password.update');
});

Route::middleware($socialCallbackMiddleware)->get('auth/social/callback/{provider}', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', SocialAuthController::providers())
    ->name('social.callback');

Route::middleware($authenticatedMiddleware)->group(function () use ($logoutAction): void {
    Route::get('/verify-email', [EmailVerificationPromptController::class, '__invoke'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [VerifyEmailController::class, '__invoke'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:sensitive-account')
        ->name('password.confirm.store');

    Route::post('/logout', $logoutAction)->name('logout');
});
