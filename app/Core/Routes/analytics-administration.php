<?php

use App\Core\Http\Controllers\WorkspaceAnalyticsAdministrationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:platform')
    ->prefix('/workspaces/{workspace}/analytics')
    ->name('core.workspace.analytics.')
    ->controller(WorkspaceAnalyticsAdministrationController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('sites.index');
        Route::post('/sites', 'create')->middleware('throttle:30,1')->name('sites.create');
        Route::get('/sites/{site}/setup', 'setup')->name('sites.setup');
        Route::post('/sites/{site}/verify', 'verify')->middleware('throttle:10,1')->name('sites.verify');
        Route::get('/sites/{site}/settings', 'settings')->name('sites.settings');
        Route::put('/sites/{site}/settings', 'update')->middleware('throttle:30,1')->name('sites.update');
        Route::delete('/sites/{site}', 'deleteSite')->middleware('password.confirm')->name('sites.delete');
        Route::get('/site-deletions/{requestId}', 'siteDeletionStatus')->name('sites.deletion-status');
        Route::post('/site-deletions/{requestId}/retry', 'retrySiteDeletion')->middleware('password.confirm')->name('sites.deletion-retry');
        Route::get('/sites/{site}/goals', 'goals')->name('goals.index');
        Route::post('/sites/{site}/goals', 'createGoal')->middleware('throttle:30,1')->name('goals.create');
        Route::put('/sites/{site}/goals/{goal}', 'updateGoal')->middleware('throttle:30,1')->name('goals.update');
        Route::delete('/sites/{site}/goals/{goal}', 'deleteGoal')->middleware('throttle:30,1')->name('goals.delete');
        Route::get('/data', 'data')->name('data.index');
        Route::post('/sites/{site}/exports', 'requestReport')->middleware('throttle:sensitive-account')->name('reports.create');
        Route::post('/sites/{site}/exports/{export}/retry', 'retryReport')->middleware('throttle:10,1')->name('reports.retry');
        Route::get('/sites/{site}/exports/{export}/download', 'downloadReport')->middleware('throttle:sensitive-account')->name('reports.download');
        Route::get('/data/export', 'workspaceExport')->middleware('throttle:sensitive-account')->name('data.export');
    });
