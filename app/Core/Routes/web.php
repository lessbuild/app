<?php

use App\Core\Http\Controllers\CoreHomeController;
use App\Core\Http\Controllers\ProjectConnectionsController;
use App\Core\Http\Controllers\WorkspaceDashboardController;
use App\Core\Http\Controllers\WorkspaceProjectsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:platform')->group(function (): void {
    Route::get('/workspaces', CoreHomeController::class)->name('core.home');

    Route::post('/workspaces/{workspace}/select', [WorkspaceProjectsController::class, 'selectWorkspace'])
        ->name('core.workspaces.select');

    Route::get('/workspaces/{workspace}/overview', WorkspaceDashboardController::class)
        ->name('core.workspace.dashboard');

    Route::prefix('workspaces/{workspace}')
        ->scopeBindings()
        ->name('core.projects.')
        ->controller(WorkspaceProjectsController::class)
        ->group(function (): void {
            Route::get('/projects', 'index')->name('index');
            Route::get('/projects/create', 'create')->name('create');
            Route::post('/projects', 'store')->name('store');
            Route::get('/projects/{project}', 'show')->name('show');
            Route::post('/projects/{project}/connections', [ProjectConnectionsController::class, 'store'])
                ->name('connections.store');
            Route::delete('/projects/{project}/connections/{connection}', [ProjectConnectionsController::class, 'destroy'])
                ->name('connections.destroy');
            Route::post('/projects/{project}/connections/{connection}/retry', [ProjectConnectionsController::class, 'retry'])
                ->name('connections.retry');
        });
});
