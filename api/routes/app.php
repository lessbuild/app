<?php

declare(strict_types=1);

// The API behind the Next.js app, under /api/app. These routes use the `web` middleware group: the session cookie
// signs people in, and writes need the X-XSRF-TOKEN header from the XSRF-TOKEN cookie. Sign-in, sign-up, two-factor
// and password reset are Fortify's routes under /api/app/auth (config/fortify.php).

use App\Http\Controllers\AccessRequests\ShowAccessRequestFormController;
use App\Http\Controllers\AccessRequests\StoreAccessRequestController;
use App\Http\Controllers\Auth\ShowCurrentUserController;
use App\Http\Controllers\Auth\ShowSignInOptionsController;
use App\Http\Controllers\Auth\StartSsoSignInController;
use App\Http\Controllers\Invitations\AcceptInvitationController;
use App\Http\Controllers\Invitations\ShowInvitationController;
use App\Http\Controllers\Shell\ShowShellController;
use Illuminate\Support\Facades\Route;

// Before signing in.
Route::get('/auth/options', ShowSignInOptionsController::class)->middleware('throttle:60,1')->name('auth.options');
Route::post('/auth/sso', StartSsoSignInController::class)->middleware(['guest', 'throttle:10,1'])->name('auth.sso');
Route::get('/access-requests', ShowAccessRequestFormController::class)->middleware('throttle:60,1')->name('access-requests.form');
Route::post('/access-requests', StoreAccessRequestController::class)->middleware('throttle:5,1')->name('access-requests.store');
Route::get('/invitations/{token}', ShowInvitationController::class)->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:30,1')->name('invitations.show');

Route::get('/auth/me', ShowCurrentUserController::class)->middleware('auth')->name('auth.me');
Route::post('/auth/confirm-with/{provider}', App\Http\Controllers\Auth\ConfirmWithProviderController::class)->middleware(['auth', 'throttle:10,1'])->name('auth.confirm-with');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('/invitations/{token}', AcceptInvitationController::class)->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:10,1')->name('invitations.accept');
});

Route::middleware(['auth', 'verified', 'account.security'])->group(function (): void {
    Route::get('/shell', ShowShellController::class)->name('shell');
});
