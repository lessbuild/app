<?php

use App\Core\Http\Controllers\WorkspaceProjectsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::post('/workspaces/{workspace}/select', [WorkspaceProjectsController::class, 'selectWorkspace'])
        ->name('core.workspaces.select');

    Route::prefix('workspaces/{workspace}')
        ->scopeBindings()
        ->name('core.projects.')
        ->controller(WorkspaceProjectsController::class)
        ->group(function (): void {
            Route::get('/projects', 'index')->name('index');
            Route::get('/projects/create', 'create')->name('create');
            Route::post('/projects', 'store')->name('store');
            Route::get('/projects/{project}', 'show')->name('show');
        });
});
