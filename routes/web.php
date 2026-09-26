<?php

declare(strict_types=1);

use App\Http\Controllers\Account\AuditLogController;
use App\Http\Controllers\Account\MembersController;
use App\Http\Controllers\Accounts\InvitationController;
use App\Http\Controllers\Auth\SocialSignInController;
use App\Http\Controllers\ComponentGalleryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/_gallery', ComponentGalleryController::class)->name('gallery');

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

Route::get('/auth/{provider}/redirect', [SocialSignInController::class, 'redirect'])->middleware(['guest', 'throttle:20,1'])->name('social.redirect');
// Shared by sign-in, connecting a provider and sudo-mode confirmation; the controller tells them apart.
Route::get('/auth/{provider}/callback', [SocialSignInController::class, 'callback'])->middleware('throttle:20,1')->name('social.callback');
Route::post('/user/confirm-password/{provider}', [SocialSignInController::class, 'confirm'])->middleware(['auth', 'throttle:10,1'])->name('social.confirm');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitations.accept');

    Route::redirect('/account', '/account/members')->name('account');
    Route::get('/account/members', [MembersController::class, 'index'])->name('account.members');
    Route::post('/account/invitations', [MembersController::class, 'invite'])->middleware('throttle:20,1')->name('account.invitations.store');
    Route::delete('/account/invitations/{invitation}', [MembersController::class, 'revokeInvitation'])->name('account.invitations.destroy');
    Route::put('/account/members/{membership}', [MembersController::class, 'updateRole'])->name('account.members.update');
    Route::delete('/account/members/{membership}', [MembersController::class, 'remove'])->name('account.members.destroy');
    Route::get('/account/audit-log', AuditLogController::class)->name('account.audit-log');

    Route::redirect('/settings', '/settings/profile')->name('settings');
    Route::get('/settings/profile', ProfileController::class)->name('settings.profile');
    // Security changes need a recent password (or passkey) confirmation, like Fortify's own 2FA and passkey routes.
    Route::get('/settings/security', SecurityController::class)->middleware('password.confirm')->name('settings.security');
    Route::post('/settings/security/social/{provider}', [SocialSignInController::class, 'connect'])->middleware(['password.confirm', 'throttle:10,1'])->name('social.connect');
    Route::get('/settings/sessions', [SessionsController::class, 'index'])->name('settings.sessions');
    Route::delete('/settings/sessions', [SessionsController::class, 'destroyOthers'])->name('settings.sessions.destroy-others');
    Route::delete('/settings/sessions/{session}', [SessionsController::class, 'destroy'])->name('settings.sessions.destroy');
    Route::delete('/settings/security/social/{provider}', [SocialSignInController::class, 'disconnect'])->middleware('password.confirm')->name('social.disconnect');
});
