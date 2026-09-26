<?php

use App\Core\Http\Controllers\WorkspaceMonitorConfigurationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:platform')
    ->prefix('/workspaces/{workspace}/monitor/configuration')
    ->name('core.workspace.monitor.configuration.')
    ->controller(WorkspaceMonitorConfigurationController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::put('/applications', 'updateApplication')->middleware('throttle:30,1')->name('applications.update');
        Route::put('/environments', 'updateEnvironment')->middleware('throttle:30,1')->name('environments.update');
        Route::put('/checks', 'updateMonitor')->middleware('throttle:30,1')->name('checks.update');
        Route::post('/checks', 'createCheck')->middleware('throttle:10,1')->name('checks.create');
    });
