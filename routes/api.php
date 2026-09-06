<?php

use App\Http\Controllers\Api\V1\ControlPlaneController;
use App\Http\Controllers\GitHubAppWebhookController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RepositoryWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('health', HealthController::class)->name('health');
Route::post('github-app/webhook', GitHubAppWebhookController::class)->name('github-app.webhook');

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('me', [ControlPlaneController::class, 'me']);
    Route::get('projects', [ControlPlaneController::class, 'projects']);
    Route::get('projects/{project}', [ControlPlaneController::class, 'project']);
    Route::put('projects/{project}/workflow', [ControlPlaneController::class, 'workflow']);
    Route::get('deployments', [ControlPlaneController::class, 'deployments']);
    Route::get('deployments/{build}', [ControlPlaneController::class, 'deployment']);
    Route::get('deployments/{build}/log', [ControlPlaneController::class, 'deploymentLog']);
    Route::post('deployments/{build}/rollback', [ControlPlaneController::class, 'rollback']);
    Route::post('deployments/{build}/promote', [ControlPlaneController::class, 'promote']);
    Route::post('environments/{environment}/deploy', [ControlPlaneController::class, 'deploy']);
    Route::patch('environments/{environment}/scale', [ControlPlaneController::class, 'scale']);
    Route::patch('environments/{environment}/runtime', [ControlPlaneController::class, 'runtime']);
    Route::put('environments/{environment}/variables', [ControlPlaneController::class, 'variables']);
});

Route::post('repositories/{repository}/webhook', RepositoryWebhookController::class)
    ->name('webhooks.repositories.receive');
