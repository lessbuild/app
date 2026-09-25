<?php

use App\Modules\Monitor\Http\Controllers\ApiDocumentationController;
use App\Modules\Monitor\Http\Controllers\DeploymentIngestController;
use App\Modules\Monitor\Http\Controllers\HeartbeatController;
use App\Modules\Monitor\Http\Controllers\IngestController;
use App\Modules\Monitor\Http\Controllers\IngestReceiptController;
use App\Modules\Monitor\Http\Controllers\OtlpIngestController;
use App\Modules\Monitor\Http\Controllers\QueueSnapshotController;
use App\Modules\Monitor\Http\Controllers\QueueWorkerController;
use App\Modules\Monitor\Http\Controllers\ReadinessController;
use App\Modules\Monitor\Http\Controllers\StripeWebhookController;
use App\Modules\Monitor\Http\Middleware\AuthenticateHeartbeatToken;
use App\Modules\Monitor\Http\Middleware\AuthenticateQueueToken;
use App\Modules\Monitor\Http\Middleware\DecodeTelemetryPayload;
use App\Modules\Monitor\Http\Middleware\ReceiveHeartbeat;
use App\Modules\Monitor\Http\Middleware\ReceiveQueueSignal;
use Illuminate\Support\Facades\Route;

Route::get('/health', ReadinessController::class)->name('api.health');

Route::middleware([
    ReceiveQueueSignal::class,
    ReceiveHeartbeat::class,
    DecodeTelemetryPayload::class,
])->group(function (): void {
    Route::post('/billing/stripe/webhook', [StripeWebhookController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('api.billing.stripe-webhook');

    Route::get('/v1/openapi.json', [ApiDocumentationController::class, 'json'])
        ->middleware('throttle:60,1')
        ->name('api.openapi');

    Route::middleware(['throttle:monitor.queue-ingress', AuthenticateQueueToken::class])->group(function (): void {
        Route::post('/v1/queues/{queue}/snapshots', [QueueSnapshotController::class, 'store'])->whereNumber('queue')->name('api.queues.snapshots.store');
        Route::post('/v1/queues/{queue}/workers', [QueueWorkerController::class, 'store'])->whereNumber('queue')->name('api.queues.workers.store');
    });

    Route::post('/v1/heartbeats/{heartbeat}', [HeartbeatController::class, 'store'])
        ->whereNumber('heartbeat')
        ->middleware(['throttle:monitor.heartbeat-ingress', AuthenticateHeartbeatToken::class])
        ->name('api.heartbeats.store');

    Route::post('/v1/deployments', [DeploymentIngestController::class, 'store'])
        ->middleware(['throttle:monitor.ingest', 'monitor.ingest.token', 'throttle:monitor.deployments'])
        ->name('api.deployments.store');

    Route::post('/v1/ingest', [IngestController::class, 'store'])
        ->middleware(['throttle:monitor.ingest', 'monitor.ingest.token'])
        ->name('api.ingest');

    Route::get('/v1/ingest/receipts/{receipt}', [IngestReceiptController::class, 'show'])
        ->whereUlid('receipt')
        ->middleware(['throttle:monitor.ingest', 'monitor.ingest.token'])
        ->name('api.ingest.receipts.show');

    Route::post('/v1/otlp/v1/{signal}', [OtlpIngestController::class, 'store'])
        ->whereIn('signal', ['traces', 'logs', 'metrics'])
        ->middleware(['throttle:monitor.ingest', 'monitor.ingest.token'])
        ->name('api.otlp');
});
