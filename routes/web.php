<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ApiTokensController;
use App\Http\Controllers\Account\AuditLogController;
use App\Http\Controllers\Account\MembersController;
use App\Http\Controllers\Account\SettingsController as AccountSettingsController;
use App\Http\Controllers\Account\SwitchAccountController;
use App\Http\Controllers\Accounts\InvitationController;
use App\Http\Controllers\Auth\SocialSignInController;
use App\Http\Controllers\ComponentGalleryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Projects\DomainController;
use App\Http\Controllers\Projects\EnvironmentController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ServiceController;
use App\Http\Controllers\Services\ServiceOverviewController;
use App\Http\Controllers\Settings\PrivacyController;
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

    Route::get('/services/{service}', ServiceOverviewController::class)->name('services.show');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('throttle:30,1')->name('projects.store');
    Route::prefix('/projects/{project}')->middleware('project.context')->group(function (): void {
        Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
        Route::delete('/checklist', [ProjectController::class, 'dismissChecklist'])->name('projects.checklist.dismiss');
        Route::get('/settings', [ProjectController::class, 'edit'])->name('projects.settings');
        Route::put('/settings', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('/', [ProjectController::class, 'destroy'])->middleware('password.confirm')->name('projects.destroy');
        Route::post('/environments', [EnvironmentController::class, 'store'])->name('projects.environments.store');
        Route::delete('/environments/{environment}', [EnvironmentController::class, 'destroy'])->name('projects.environments.destroy');
        Route::get('/domains', [DomainController::class, 'index'])->name('projects.domains');
        Route::post('/domains', [DomainController::class, 'store'])->middleware('throttle:30,1')->name('projects.domains.store');
        Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:20,1')->name('projects.domains.verify');
        Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('projects.domains.destroy');
        Route::get('/services/{service}', [ServiceController::class, 'show'])->name('projects.services.show');
        Route::post('/services/{service}', [ServiceController::class, 'store'])->name('projects.services.store');
        Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('projects.services.destroy');
    });

    Route::post('/accounts/{account}/switch', SwitchAccountController::class)->name('accounts.switch');
    Route::redirect('/account', '/account/members')->name('account');
    Route::get('/account/members', [MembersController::class, 'index'])->name('account.members');
    Route::post('/account/invitations', [MembersController::class, 'invite'])->middleware('throttle:20,1')->name('account.invitations.store');
    Route::delete('/account/invitations/{invitation}', [MembersController::class, 'revokeInvitation'])->name('account.invitations.destroy');
    Route::put('/account/members/{membership}', [MembersController::class, 'updateRole'])->name('account.members.update');
    Route::delete('/account/members/{membership}', [MembersController::class, 'remove'])->name('account.members.destroy');
    Route::put('/account/members/{membership}/services', [MembersController::class, 'updateServices'])->name('account.members.services');
    Route::get('/account/api-tokens', [ApiTokensController::class, 'index'])->name('account.api-tokens');
    Route::post('/account/api-tokens', [ApiTokensController::class, 'store'])->middleware(['password.confirm', 'throttle:20,1'])->name('account.api-tokens.store');
    Route::delete('/account/api-tokens/{token}', [ApiTokensController::class, 'destroy'])->whereNumber('token')->name('account.api-tokens.destroy');
    Route::get('/account/audit-log', AuditLogController::class)->name('account.audit-log');
    Route::get('/account/settings', [AccountSettingsController::class, 'edit'])->name('account.settings');
    Route::put('/account/settings', [AccountSettingsController::class, 'update'])->name('account.settings.update');
    Route::delete('/account/settings', [AccountSettingsController::class, 'destroy'])->middleware('password.confirm')->name('account.settings.destroy');

    Route::redirect('/settings', '/settings/profile')->name('settings');
    Route::get('/settings/profile', ProfileController::class)->name('settings.profile');
    // Security changes need a recent password (or passkey) confirmation, like Fortify's own 2FA and passkey routes.
    Route::get('/settings/security', SecurityController::class)->middleware('password.confirm')->name('settings.security');
    Route::post('/settings/security/social/{provider}', [SocialSignInController::class, 'connect'])->middleware(['password.confirm', 'throttle:10,1'])->name('social.connect');
    Route::get('/settings/sessions', [SessionsController::class, 'index'])->name('settings.sessions');
    Route::get('/settings/privacy', [PrivacyController::class, 'index'])->name('settings.privacy');
    Route::get('/settings/privacy/export', [PrivacyController::class, 'export'])->middleware('throttle:6,1')->name('settings.privacy.export');
    Route::delete('/settings/privacy/user', [PrivacyController::class, 'destroy'])->middleware('password.confirm')->name('settings.privacy.destroy');
    Route::delete('/settings/sessions', [SessionsController::class, 'destroyOthers'])->name('settings.sessions.destroy-others');
    Route::delete('/settings/sessions/{session}', [SessionsController::class, 'destroy'])->name('settings.sessions.destroy');
    Route::delete('/settings/security/social/{provider}', [SocialSignInController::class, 'disconnect'])->middleware('password.confirm')->name('social.disconnect');
});
