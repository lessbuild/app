<?php

use App\Core\Http\Controllers\WorkspaceCredentialMutationController;
use App\Core\Http\Middleware\EnsureWorkspaceFeatureRollout;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:platform', EnsureWorkspaceFeatureRollout::class.':credential_inventory'])
    ->prefix('/workspaces/{workspace}/credentials')
    ->name('core.workspace.credentials.')
    ->controller(WorkspaceCredentialMutationController::class)
    ->group(function (): void {
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::post('/{credential}/rotate', 'rotate')->middleware('throttle:10,1')->name('rotate');
        Route::delete('/{credential}', 'revoke')->middleware('throttle:10,1')->name('revoke');
    });
