<?php

declare(strict_types=1);

use App\Http\Controllers\Analytics\CollectEventsController;
use App\Http\Controllers\Analytics\PreflightCollectController;
use App\Http\Controllers\Api\V1\ShowAccountController;
use App\Http\Controllers\Monitoring\RecordHeartbeatController;
use App\Http\Controllers\Monitoring\RecordQueueSnapshotController;
use App\Http\Controllers\Monitoring\RecordQueueWorkerController;
use App\Http\Middleware\AuthenticateHeartbeatToken;
use App\Http\Middleware\AuthenticateQueueToken;
use Illuminate\Support\Facades\Route;

// Token API. Every route needs a token (auth:sanctum), resolves the token's account and checks scopes.
Route::prefix('v1')->middleware(['auth:sanctum', 'token.account', 'throttle:api'])->group(function (): void {
    Route::get('/account', ShowAccountController::class)->middleware('abilities:account:read')->name('api.v1.account');
});

// Public contract from the old Analytics app: the tracker posts here without a token.
Route::post('/v1/collect/{publicId}', CollectEventsController::class)->middleware('throttle:collect')->where('publicId', '[A-Za-z0-9]+')->name('analytics.collect');
Route::options('/v1/collect/{publicId}', PreflightCollectController::class)->middleware('throttle:collect')->where('publicId', '[A-Za-z0-9]+');

// Public contracts from the old Monitor app: each monitor has its own bearer key.
Route::middleware(['throttle:queue-ingress', AuthenticateQueueToken::class])->group(function (): void {
    Route::post('/v1/queues/{queue}/snapshots', RecordQueueSnapshotController::class)->whereNumber('queue')->name('api.queues.snapshots.store');
    Route::post('/v1/queues/{queue}/workers', RecordQueueWorkerController::class)->whereNumber('queue')->name('api.queues.workers.store');
});
Route::post('/v1/heartbeats/{heartbeat}', RecordHeartbeatController::class)
    ->whereNumber('heartbeat')
    ->middleware(['throttle:heartbeat-ingress', AuthenticateHeartbeatToken::class])
    ->name('api.heartbeats.store');
