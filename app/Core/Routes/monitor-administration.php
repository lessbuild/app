<?php

use App\Core\Http\Controllers\WorkspaceMonitorAdministrationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:platform')->prefix('/workspaces/{workspace}/monitor')->name('core.workspace.monitor.')->group(function (): void {
    Route::get('/alerts', [WorkspaceMonitorAdministrationController::class, 'alerts'])->name('alerts');
    Route::post('/alerts/rules', [WorkspaceMonitorAdministrationController::class, 'saveRule'])->middleware('throttle:30,1')->name('rules.store');
    Route::patch('/alerts/rules', [WorkspaceMonitorAdministrationController::class, 'saveRule'])->middleware('throttle:30,1')->name('rules.update');
    Route::delete('/alerts/rules', [WorkspaceMonitorAdministrationController::class, 'archiveRule'])->middleware('throttle:30,1')->name('rules.archive');
    Route::put('/alerts/routing', [WorkspaceMonitorAdministrationController::class, 'routing'])->middleware('throttle:30,1')->name('routing.update');
    Route::put('/alerts/escalations', [WorkspaceMonitorAdministrationController::class, 'escalations'])->middleware('throttle:30,1')->name('escalations.update');

    Route::get('/destinations', [WorkspaceMonitorAdministrationController::class, 'destinations'])->name('destinations');
    Route::post('/destinations', [WorkspaceMonitorAdministrationController::class, 'saveDestination'])->middleware('throttle:30,1')->name('destinations.store');
    Route::patch('/destinations', [WorkspaceMonitorAdministrationController::class, 'saveDestination'])->middleware('throttle:30,1')->name('destinations.update');
    Route::delete('/destinations/{action}', [WorkspaceMonitorAdministrationController::class, 'changeDestination'])->where('action', 'archive')->middleware('throttle:30,1')->name('destinations.archive');
    Route::post('/destinations/{action}', [WorkspaceMonitorAdministrationController::class, 'changeDestination'])->whereIn('action', ['rotate', 'test'])->middleware('throttle:10,1')->name('destinations.action');
    Route::post('/deliveries/retry', [WorkspaceMonitorAdministrationController::class, 'retryDelivery'])->middleware('throttle:10,1')->name('deliveries.retry');

    Route::get('/settings', [WorkspaceMonitorAdministrationController::class, 'settings'])->name('settings');
    Route::put('/settings/notifications', [WorkspaceMonitorAdministrationController::class, 'saveSettings'])->middleware('throttle:30,1')->name('settings.notifications');
    Route::get('/integrations', [WorkspaceMonitorAdministrationController::class, 'integrations'])->name('integrations');
    Route::get('/audit', [WorkspaceMonitorAdministrationController::class, 'audit'])->name('audit');
    Route::get('/export', [WorkspaceMonitorAdministrationController::class, 'export'])->middleware('throttle:10,1')->name('export');
});
