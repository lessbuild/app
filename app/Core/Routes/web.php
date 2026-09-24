<?php

use App\Core\Http\Controllers\Auth\PlatformSessionController;
use App\Core\Http\Controllers\CoreHomeController;
use App\Core\Http\Controllers\MarketingController;
use App\Core\Http\Controllers\ProjectConnectionsController;
use App\Core\Http\Controllers\ProjectEnvironmentsController;
use App\Core\Http\Controllers\WorkspaceDashboardController;
use App\Core\Http\Controllers\WorkspaceDashboardPreferencesController;
use App\Core\Http\Controllers\WorkspaceProjectsController;
use App\Core\Http\Controllers\WorkspaceSearchController;
use App\Core\Http\Controllers\WorkspaceTeamController;
use App\Core\Http\Controllers\WorkspaceWorkflowActivityController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingController::class, 'home'])->name('core.entry');
Route::get('/{product}', [MarketingController::class, 'showProduct'])
    ->whereIn('product', ['deployer', 'monitor', 'analytics'])
    ->name('core.marketing.product');

Route::middleware('auth:platform')->group(function (): void {
    Route::get('/workspaces', CoreHomeController::class)->name('core.home');
    Route::post('/core/logout', [PlatformSessionController::class, 'destroy'])->name('core.logout');

    Route::post('/workspaces/{workspace}/select', [WorkspaceProjectsController::class, 'selectWorkspace'])
        ->name('core.workspaces.select');

    Route::get('/workspaces/{workspace}/overview', WorkspaceDashboardController::class)
        ->name('core.workspace.dashboard');

    Route::get('/workspaces/{workspace}/workflows', WorkspaceWorkflowActivityController::class)
        ->name('core.workspace.workflows');

    Route::post('/workspaces/{workspace}/dashboard/views', [WorkspaceDashboardPreferencesController::class, 'storeView'])
        ->name('core.workspace.views.store');
    Route::put('/workspaces/{workspace}/dashboard/views/{view}', [WorkspaceDashboardPreferencesController::class, 'updateView'])
        ->scopeBindings()
        ->name('core.workspace.views.update');
    Route::delete('/workspaces/{workspace}/dashboard/views/{view}', [WorkspaceDashboardPreferencesController::class, 'destroyView'])
        ->scopeBindings()
        ->name('core.workspace.views.destroy');
    Route::put('/workspaces/{workspace}/projects/{project}/pins/{visibility}', [WorkspaceDashboardPreferencesController::class, 'pin'])
        ->scopeBindings()
        ->whereIn('visibility', ['personal', 'workspace'])
        ->name('core.workspace.project-pins.update');
    Route::delete('/workspaces/{workspace}/projects/{project}/pins/{visibility}', [WorkspaceDashboardPreferencesController::class, 'unpin'])
        ->scopeBindings()
        ->whereIn('visibility', ['personal', 'workspace'])
        ->name('core.workspace.project-pins.destroy');

    Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class)
        ->middleware('throttle:60,1')
        ->name('core.workspace.search');

    Route::prefix('workspaces/{workspace}/team')
        ->scopeBindings()
        ->name('core.workspace.team.')
        ->controller(WorkspaceTeamController::class)
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::post('/invitations', 'storeInvitation')->middleware('throttle:10,1')->name('invitations.store');
            Route::delete('/invitations/{invitation}', 'revokeInvitation')->name('invitations.destroy');
            Route::put('/memberships/{membership}/role', 'updateRole')->name('memberships.role.update');
            Route::put('/memberships/{membership}/products/{product}', 'updateProductAccess')
                ->whereIn('product', ['deployer', 'monitor', 'analytics'])
                ->name('memberships.products.update');
            Route::delete('/memberships/{membership}', 'revokeMembership')->name('memberships.destroy');
        });

    Route::prefix('workspaces/{workspace}')
        ->scopeBindings()
        ->name('core.projects.')
        ->controller(WorkspaceProjectsController::class)
        ->group(function (): void {
            Route::get('/projects', 'index')->name('index');
            Route::get('/projects/create', 'create')->name('create');
            Route::post('/projects', 'store')->name('store');
            Route::get('/projects/{project}/edit', 'edit')->name('edit');
            Route::put('/projects/{project}', 'update')->name('update');
            Route::get('/projects/{project}', 'show')->name('show');
            Route::post('/projects/{project}/archive', 'archive')->name('archive');
            Route::post('/projects/{project}/restore', 'restore')->name('restore');
            Route::post('/projects/{project}/resources', 'storeResource')->name('resources.store');
            Route::post('/projects/{project}/environments', [ProjectEnvironmentsController::class, 'store'])
                ->name('environments.store');
            Route::post('/projects/{project}/connections', [ProjectConnectionsController::class, 'store'])
                ->name('connections.store');
            Route::delete('/projects/{project}/connections/{connection}', [ProjectConnectionsController::class, 'destroy'])
                ->name('connections.destroy');
            Route::post('/projects/{project}/connections/{connection}/deliveries/{delivery}/retry', [ProjectConnectionsController::class, 'retry'])
                ->name('connections.deliveries.retry');
            Route::post('/projects/{project}/connections/{connection}/automation', [ProjectConnectionsController::class, 'automation'])
                ->name('connections.automation');
        });
});
