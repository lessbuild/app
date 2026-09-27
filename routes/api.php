<?php

declare(strict_types=1);

use App\Http\Controllers\Analytics\CollectEventsController;
use App\Http\Controllers\Analytics\PreflightCollectController;
use App\Http\Controllers\Api\V1\Deploy\ApplyConfigurationReviewController;
use App\Http\Controllers\Api\V1\Deploy\CreateConfigurationReviewController;
use App\Http\Controllers\Api\V1\Deploy\DeployEnvironmentController;
use App\Http\Controllers\Api\V1\Deploy\ListDeploymentsController;
use App\Http\Controllers\Api\V1\Deploy\ListProjectsController;
use App\Http\Controllers\Api\V1\Deploy\PlanConfigurationController;
use App\Http\Controllers\Api\V1\Deploy\PromoteDeploymentController;
use App\Http\Controllers\Api\V1\Deploy\ReplaceEnvironmentVariablesController;
use App\Http\Controllers\Api\V1\Deploy\RollbackDeploymentController;
use App\Http\Controllers\Api\V1\Deploy\ScaleEnvironmentController;
use App\Http\Controllers\Api\V1\Deploy\ShowConfigurationApplicationController;
use App\Http\Controllers\Api\V1\Deploy\ShowDeploymentController;
use App\Http\Controllers\Api\V1\Deploy\ShowDeploymentLogController;
use App\Http\Controllers\Api\V1\Deploy\ShowMeController;
use App\Http\Controllers\Api\V1\Deploy\ShowProjectController;
use App\Http\Controllers\Api\V1\Deploy\UpdateConfigurationOperationController;
use App\Http\Controllers\Api\V1\ShowAccountController;
use App\Http\Controllers\Deploy\ReceiveGitHubAppWebhookController;
use App\Http\Controllers\Deploy\ReceiveRepositoryWebhookController;
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

    // Deployer API v1 (a public contract): the same paths, fields and status codes, over v2 tokens with Deploy scopes.
    Route::middleware('abilities:deploy:read')->group(function (): void {
        Route::get('/me', ShowMeController::class)->name('api.v1.me');
        Route::get('/projects', ListProjectsController::class)->name('api.v1.projects');
        Route::get('/projects/{project}', ShowProjectController::class)->name('api.v1.projects.show');
        Route::get('/deployments', ListDeploymentsController::class)->name('api.v1.deployments');
        Route::get('/deployments/{build}', ShowDeploymentController::class)->whereNumber('build')->name('api.v1.deployments.show');
        Route::get('/deployments/{build}/log', ShowDeploymentLogController::class)->whereNumber('build')->name('api.v1.deployments.log');
        Route::get('/projects/{project}/configuration/applications/{application}', ShowConfigurationApplicationController::class)->whereNumber('application')->name('api.v1.configuration.applications.show');
    });
    Route::middleware('abilities:deploy:write')->group(function (): void {
        Route::post('/deployments/{build}/rollback', RollbackDeploymentController::class)->whereNumber('build')->name('api.v1.deployments.rollback');
        Route::post('/deployments/{build}/promote', PromoteDeploymentController::class)->whereNumber('build')->name('api.v1.deployments.promote');
        Route::post('/environments/{environment}/deploy', DeployEnvironmentController::class)->name('api.v1.environments.deploy');
        Route::patch('/environments/{environment}/scale', ScaleEnvironmentController::class)->name('api.v1.environments.scale');
        Route::put('/environments/{environment}/variables', ReplaceEnvironmentVariablesController::class)->name('api.v1.environments.variables');
        Route::post('/projects/{project}/configuration/plan', PlanConfigurationController::class)->name('api.v1.configuration.plan');
        Route::post('/projects/{project}/configuration/reviews', CreateConfigurationReviewController::class)->name('api.v1.configuration.reviews');
        Route::post('/projects/{project}/configuration/reviews/{review}/apply', ApplyConfigurationReviewController::class)->whereNumber('review')->name('api.v1.configuration.apply');
        Route::post('/projects/{project}/configuration/applications/{application}/operations/{operation}/{action}', UpdateConfigurationOperationController::class)
            ->whereNumber(['application', 'operation'])->whereIn('action', ['cancel', 'retry'])->name('api.v1.configuration.operations');
    });
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

// Git push webhooks for a repository (Deployer's public contract), verified with the repository's secret.
Route::post('/repositories/{repository}/webhook', ReceiveRepositoryWebhookController::class)->whereNumber('repository')->middleware('throttle:120,1')->name('webhooks.repositories.receive');
Route::post('/github-app/webhook', ReceiveGitHubAppWebhookController::class)->middleware('throttle:600,1')->name('github-app.webhook');
