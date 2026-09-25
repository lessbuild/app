<?php

use App\Core\Http\Controllers\WorkspaceDeployerConfigurationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:platform')
    ->prefix('/workspaces/{workspace}/deployer/configuration')
    ->name('core.workspace.deployer.configuration.')
    ->controller(WorkspaceDeployerConfigurationController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');

        Route::prefix('/projects/{project}')
            ->scopeBindings()
            ->name('projects.')
            ->group(function (): void {
                Route::get('/', 'project')->name('show');
                Route::patch('/previews', 'updateProjectPreviews')->middleware('throttle:30,1')->name('previews.update');

                Route::prefix('/environments/{environment}')
                    ->scopeBindings()
                    ->name('environments.')
                    ->group(function (): void {
                        Route::get('/', 'environment')->name('show');
                        Route::patch('/', 'updateEnvironment')->middleware('throttle:30,1')->name('update');
                    });
            });
    });
