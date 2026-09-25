<?php

use App\Core\Http\Controllers\WorkspaceProjectBlueprintController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:platform')->prefix('/workspaces/{workspace}/blueprints')->name('core.workspace.blueprints.')
    ->controller(WorkspaceProjectBlueprintController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::post('/{blueprint}/versions', 'storeVersion')->middleware('throttle:10,1')->name('versions.store');
        Route::get('/versions/{blueprintVersion}', 'show')->name('show');
        Route::post('/versions/{blueprintVersion}/preview', 'preview')->middleware('throttle:20,1')->name('preview');
        Route::post('/versions/{blueprintVersion}/apply', 'apply')->middleware('throttle:5,1')->name('apply');
        Route::get('/runs/{blueprintRun}', 'progress')->name('runs.show');
        Route::post('/runs/{blueprintRun}/retry', 'retry')->middleware('throttle:5,1')->name('runs.retry');
    });
