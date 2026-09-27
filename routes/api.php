<?php

declare(strict_types=1);

use App\Http\Controllers\Analytics\CollectEventsController;
use App\Http\Controllers\Analytics\PreflightCollectController;
use App\Http\Controllers\Api\V1\ShowAccountController;
use App\Http\Controllers\Monitoring\RecordHeartbeatController;
use App\Http\Controllers\Monitoring\RecordQueueSnapshotController;
use App\Http\Controllers\Monitoring\RecordQueueWorkerController;
use App\Http\Controllers\Telemetry\IngestEventsController;
use App\Http\Controllers\Telemetry\IngestOtlpController;
use App\Http\Controllers\Telemetry\RecordDeploymentApiController;
use App\Http\Controllers\Telemetry\ShowIngestReceiptController;
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

// Telemetry ingest (public contracts from the old Monitor app), authenticated with an environment's ingest key.
Route::middleware(['throttle:ingest', 'ingest.token'])->group(function (): void {
    Route::post('/v1/ingest', IngestEventsController::class)->name('api.ingest');
    Route::get('/v1/ingest/receipts/{receipt}', ShowIngestReceiptController::class)->whereUlid('receipt')->name('api.ingest.receipts.show');
    Route::post('/v1/otlp/v1/{signal}', IngestOtlpController::class)->whereIn('signal', ['traces', 'logs', 'metrics'])->name('api.otlp');
    Route::post('/v1/deployments', RecordDeploymentApiController::class)->middleware('throttle:deployments')->name('api.deployments.store');
});
