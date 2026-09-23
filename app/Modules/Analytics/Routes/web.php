<?php

use App\Core\Http\Controllers\Auth\PlatformSessionController;
use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Analytics\Http\Controllers\Auth\SessionController;
use App\Modules\Analytics\Http\Controllers\GoalController;
use App\Modules\Analytics\Http\Controllers\InvitationController;
use App\Modules\Analytics\Http\Controllers\ProfileController;
use App\Modules\Analytics\Http\Controllers\ReadinessController;
use App\Modules\Analytics\Http\Controllers\ReportExportController;
use App\Modules\Analytics\Http\Controllers\SiteController;
use App\Modules\Analytics\Http\Controllers\SiteSettingsController;
use App\Modules\Analytics\Http\Controllers\TeamController;
use App\Modules\Analytics\Http\Controllers\WorkspaceController;
use App\Modules\Analytics\Http\Controllers\WorkspaceSearchController;
use App\Modules\Analytics\Livewire\Dashboard\Overview;
use Illuminate\Support\Facades\Route;

$analyticsAuthentication = app(ProductAuthentication::class);
$authenticatedMiddleware = $analyticsAuthentication->authenticatedMiddleware('analytics');
$logoutAction = $analyticsAuthentication->usesCoreAuthority('analytics')
    ? [PlatformSessionController::class, 'destroy']
    : [SessionController::class, 'destroy'];

Route::redirect('/', '/dashboard')->name('home');
Route::get('/ready', ReadinessController::class)->name('ready');
Route::view('/verify-email', 'analytics::auth.verify-email')->middleware($authenticatedMiddleware)->name('verification.notice');

Route::middleware($authenticatedMiddleware)->post('/logout', $logoutAction)->name('logout');

Route::middleware([...$authenticatedMiddleware, 'verified:analytics.verification.notice'])->group(function (): void {
    Route::get('/dashboard', Overview::class)->name('dashboard');
    Route::get('/account', [ProfileController::class, 'edit'])->name('account.profile');
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspace}/select', [WorkspaceController::class, 'select'])->name('workspaces.select');
    Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class)
        ->middleware('throttle:60,1')
        ->name('workspace.search');
    Route::get('/workspaces/{workspace}/team', [TeamController::class, 'index'])->name('workspaces.team');
    Route::post('/workspaces/{workspace}/invitations', [TeamController::class, 'invite'])->middleware('password.confirm')->name('workspaces.invitations.store');
    Route::put('/workspaces/{workspace}/members/{user}', [TeamController::class, 'updateRole'])->middleware('password.confirm')->name('workspaces.members.update');
    Route::delete('/workspaces/{workspace}/members/{user}', [TeamController::class, 'remove'])->middleware('password.confirm')->name('workspaces.members.destroy');
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
    Route::get('/exports/{token}', [ReportExportController::class, 'show'])->name('reports.exports.show');
    Route::get('/exports/{token}/download', [ReportExportController::class, 'download'])->name('reports.exports.download');
    Route::get('/sites/create', [SiteController::class, 'create'])->name('sites.create');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{site}/setup', [SiteController::class, 'setup'])->name('sites.setup');
    Route::post('/sites/{site}/verify', [SiteController::class, 'verify'])->name('sites.verify');
    Route::get('/sites/{site}/settings', [SiteSettingsController::class, 'edit'])->name('sites.settings');
    Route::put('/sites/{site}/settings', [SiteSettingsController::class, 'update'])->name('sites.settings.update');
    Route::delete('/sites/{site}', [SiteSettingsController::class, 'destroy'])->middleware('password.confirm')->name('sites.destroy');
    Route::post('/sites/{site}/exports', [ReportExportController::class, 'store'])->name('reports.exports.store');
    Route::get('/sites/{site}/goals', [GoalController::class, 'index'])->name('goals.index');
    Route::get('/sites/{site}/goals/create', [GoalController::class, 'create'])->name('goals.create');
    Route::post('/sites/{site}/goals', [GoalController::class, 'store'])->name('goals.store');
    Route::get('/sites/{site}/goals/{goal}/edit', [GoalController::class, 'edit'])->name('goals.edit');
    Route::put('/sites/{site}/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    Route::delete('/sites/{site}/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');
});
