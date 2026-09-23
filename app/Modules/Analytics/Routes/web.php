<?php

use App\Modules\Analytics\Http\Controllers\GoalController;
use App\Modules\Analytics\Http\Controllers\InvitationController;
use App\Modules\Analytics\Http\Controllers\ProfileController;
use App\Modules\Analytics\Http\Controllers\ReadinessController;
use App\Modules\Analytics\Http\Controllers\ReportExportController;
use App\Modules\Analytics\Http\Controllers\SiteController;
use App\Modules\Analytics\Http\Controllers\SiteSettingsController;
use App\Modules\Analytics\Http\Controllers\TeamController;
use App\Modules\Analytics\Http\Controllers\WorkspaceController;
use App\Modules\Analytics\Livewire\Dashboard\Overview;
use Illuminate\Support\Facades\Route;

Route::view('/', 'analytics::marketing.home')->name('home');
Route::get('/ready', ReadinessController::class)->name('ready');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', Overview::class)->name('dashboard');
    Route::get('/account', [ProfileController::class, 'edit'])->name('account.profile');
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspace}/select', [WorkspaceController::class, 'select'])->name('workspaces.select');
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
