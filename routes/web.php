<?php

use App\Actions\Server\CollectServerLogAction;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BuildRevisionCallbackController;
use App\Http\Controllers\BuildsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\ProviderConnectionController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RecipesController;
use App\Http\Controllers\RepositoriesController;
use App\Http\Controllers\RepositoryWebhookSettingsController;
use App\Http\Controllers\ServerCommandsController;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebsitesController;
use App\Http\Livewire\ServerShow;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Build;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\Website;
use App\Services\RepositoryDeploymentPlan;
use App\Services\ServerProvisioningPlan;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('scenes.index');
});

Route::middleware('auth')->group(function () {
    Route::get('home', DashboardController::class)->name('dashboard');
    Route::get('activity/export', [ActivityController::class, 'export'])
        ->name('activity.export');
    Route::get('activity', ActivityController::class)->name('activity.index');
    Route::get('notifications/export', [NotificationsController::class, 'export'])
        ->name('notifications.export');
    Route::get('notifications', [NotificationsController::class, 'index'])
        ->name('notifications.index');
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::post('notifications/clear-read', [NotificationsController::class, 'clearRead'])
        ->name('notifications.clear-read');
    Route::post('notifications/{notification}/read', [NotificationsController::class, 'read'])
        ->name('notifications.read');
    Route::post('notifications/{notification}/unread', [NotificationsController::class, 'unread'])
        ->name('notifications.unread');
    Route::delete('notifications/{notification}', [NotificationsController::class, 'destroy'])
        ->name('notifications.destroy');

    Route::get('account', [UsersController::class, 'index'])->name('account.index');
    Route::patch('account/profile', [UsersController::class, 'updateProfile'])
        ->name('account.profile.update');
    Route::patch('account/password', [UsersController::class, 'updatePassword'])
        ->name('account.password.update');
    Route::get('websites/export', [WebsitesController::class, 'export'])
        ->name('websites.export');
    Route::resource('websites', WebsitesController::class);
    Route::post('websites/{website}/placement/cleanup', [WebsitesController::class, 'retryPlacementCleanup'])
        ->name('websites.placement.cleanup');
    Route::post('websites/{website}/provisioning/retry', [WebsitesController::class, 'retryProvisioning'])
        ->name('websites.provisioning.retry');
    Route::post('websites/{website}/health/check', [WebsitesController::class, 'checkHealth'])
        ->name('websites.health.check');
    Route::get('websites/{website}/provisioning-log', [WebsitesController::class, 'downloadProvisioningLog'])
        ->name('websites.provisioning-log.download');

    Route::get('servers/export', [ServersController::class, 'export'])
        ->name('servers.export');
    Route::resource('servers', ServersController::class)->only(['index', 'create', 'store', 'destroy']);
    Route::get('servers/{server}', ServerShow::class)
        ->middleware('can:view,server')
        ->name('servers.show');
    Route::get('servers/{server}/logs/{type}', [ServersController::class, 'downloadLog'])
        ->whereIn('type', CollectServerLogAction::TYPES)
        ->name('servers.logs.download');
    Route::get('servers/{server}/commands', [ServerCommandsController::class, 'index'])
        ->name('servers.commands.index');
    Route::get('servers/{server}/commands/export', [ServerCommandsController::class, 'export'])
        ->name('servers.commands.export');
    Route::post('servers/{server}/commands/{execution}/cancel', [ServerCommandsController::class, 'cancel'])
        ->whereNumber('execution')
        ->name('servers.commands.cancel');
    Route::post('servers/{server}/commands/{execution}/rerun', [ServerCommandsController::class, 'rerun'])
        ->whereNumber('execution')
        ->name('servers.commands.rerun');
    Route::delete('servers/{server}/commands/{execution}', [ServerCommandsController::class, 'destroy'])
        ->whereNumber('execution')
        ->name('servers.commands.destroy');
    Route::get('servers/{server}/commands/{execution}/output', [ServerCommandsController::class, 'downloadOutput'])
        ->whereNumber('execution')
        ->name('servers.commands.output');
    Route::post('servers/{server}/initialization/retry', [ServersController::class, 'retryInitialization'])
        ->name('servers.initialization.retry');
    Route::post('servers/{server}/provisioning/retry', [ServersController::class, 'retryRemoteProvisioning'])
        ->name('servers.provisioning.retry');

    Route::get('builds/export', [BuildsController::class, 'export'])
        ->name('builds.export');
    Route::get('builds/{build}/log', [BuildsController::class, 'downloadLog'])
        ->name('builds.log.download');
    Route::resource('builds', BuildsController::class)->only(['index', 'show']);
    Route::post('builds/{build}/cancel', [BuildsController::class, 'cancel'])
        ->name('builds.cancel');
    Route::post('builds/{build}/redeploy', [BuildsController::class, 'redeploy'])
        ->name('builds.redeploy');
    Route::get('repositories/export', [RepositoriesController::class, 'export'])
        ->name('repositories.export');
    Route::get('repositories/{repository}/webhook-deliveries/export', [RepositoriesController::class, 'exportWebhookDeliveries'])
        ->name('repositories.webhook-deliveries.export');
    Route::resource('repositories', RepositoriesController::class);
    Route::get('recipes/export', [RecipesController::class, 'export'])
        ->name('recipes.export');
    Route::resource('recipes', RecipesController::class)->except('show');
    Route::post('recipes/{recipe}/duplicate', [RecipesController::class, 'duplicate'])
        ->name('recipes.duplicate');
    Route::get('providers/export', [ProviderController::class, 'export'])
        ->name('providers.export');
    Route::resource('providers', ProviderController::class);
    Route::post('providers/{provider}/connection/test', ProviderConnectionController::class)
        ->middleware('throttle:6,1')
        ->name('providers.connection.test');
    Route::post('repositories/{repository}/deploy', [RepositoriesController::class, 'deploy'])
        ->name('repositories.deploy');
    Route::post('repositories/{repository}/webhook', [RepositoryWebhookSettingsController::class, 'store'])
        ->name('repositories.webhook.store');
    Route::delete('repositories/{repository}/webhook', [RepositoryWebhookSettingsController::class, 'destroy'])
        ->name('repositories.webhook.destroy');
});

Route::post('servers/{server}/provisioning/callback/status', function (Server $server) {
    if ($server->provisioning_token && ! hash_equals($server->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    if (! in_array($server->provisioning_status, [
        Server::STATUS_QUEUED,
        Server::STATUS_WAITING_FOR_IP,
        Server::STATUS_PROVISIONING,
    ], true)) {
        return response()->noContent();
    }

    $finalStage = app(ServerProvisioningPlan::class)->finalStage($server);
    $data = request()->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
    if ($data['status'] > $server->setup_stage) {
        $server->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === $finalStage) {
        $server->update([
            'provisioning_status' => Server::STATUS_ACTIVE,
            'password' => null,
            'provisioned_at' => now(),
            'provisioning_error' => null,
            'provisioning_failure_phase' => null,
            'provisioning_process_id' => null,
            'provisioning_process_path' => null,
            'initialization_token' => null,
        ]);
    }
})->middleware('signed')->name('callbacks.server');

Route::post('websites/{website}/provisioning/callback/status', function (Website $website) {
    if ($website->provisioning_token && ! hash_equals($website->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    if (! in_array($website->provisioning_status, [
        Website::STATUS_QUEUED,
        Website::STATUS_PROVISIONING,
    ], true)) {
        return response()->noContent();
    }

    $finalStage = app(WebsiteProvisioningPlan::class)->finalStage();
    $data = request()->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
    if ($data['status'] > $website->setup_stage) {
        $website->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === $finalStage) {
        $previousServerId = $website->previous_server_id;
        $website->update([
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now(),
            'provisioning_error' => null,
        ]);

        if ($previousServerId) {
            CleanupWebsitePlacementJob::dispatch(
                $website->id,
                $previousServerId,
                $website->deployment_slug,
            );
        }
    }
})->middleware('signed')->name('callbacks.website');

Route::post('servers/{server}/provisioning/callback/failed', function (Server $server) {
    if ($server->provisioning_token && ! hash_equals($server->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);
    if (in_array($server->provisioning_status, [
        Server::STATUS_QUEUED,
        Server::STATUS_WAITING_FOR_IP,
        Server::STATUS_PROVISIONING,
    ], true)) {
        $server->update([
            'password' => null,
            'provisioning_status' => Server::STATUS_FAILED,
            'provisioning_error' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
            'provisioning_failure_phase' => Server::FAILURE_REMOTE,
            'provisioning_process_id' => null,
            'provisioning_process_path' => null,
            'initialization_token' => null,
        ]);
        $server->logSnapshots()->updateOrCreate(
            ['type' => 'provisioning'],
            [
                'status' => ServerLogSnapshot::STATUS_FAILED,
                'error' => $server->provisioning_error,
                'refreshed_at' => now(),
            ],
        );
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.server.failed');

Route::post('servers/{server}/provisioning/callback/log', function (Server $server) {
    DB::transaction(function () use ($server): void {
        $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
        if ($locked->provisioning_token && ! hash_equals($locked->provisioning_token, (string) request('attempt'))) {
            return;
        }

        $data = request()->validate([
            'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.server_log_max_characters'))],
        ]);
        $locked->logSnapshots()->updateOrCreate(
            ['type' => 'provisioning'],
            [
                'status' => ServerLogSnapshot::STATUS_READY,
                'log' => $data['log'],
                'error' => null,
                'refreshed_at' => now(),
            ],
        );
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.server.log');

Route::post('websites/{website}/provisioning/callback/failed', function (Website $website) {
    if ($website->provisioning_token && ! hash_equals($website->provisioning_token, (string) request('attempt'))) {
        return response()->noContent();
    }

    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);
    if (in_array($website->provisioning_status, [
        Website::STATUS_QUEUED,
        Website::STATUS_PROVISIONING,
    ], true)) {
        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
        ]);
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.website.failed');

Route::post('websites/{website}/provisioning/callback/log', function (Website $website) {
    DB::transaction(function () use ($website): void {
        $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
        if ($locked->provisioning_token && ! hash_equals($locked->provisioning_token, (string) request('attempt'))) {
            return;
        }

        $data = request()->validate([
            'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.website_log_max_characters'))],
        ]);
        $locked->logs()->updateOrCreate(
            ['type' => Website::PROVISIONING_LOG_TYPE],
            ['log' => $data['log']],
        );
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.website.log');

Route::post('builds/{build}/deployment/callback/status', function (Build $build) {
    $finalStage = app(RepositoryDeploymentPlan::class)->finalStage();
    $data = request()->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
    DB::transaction(function () use ($build, $data, $finalStage): void {
        $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
        if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
            return;
        }

        $repository = $locked->repository;
        if ($data['status'] > $repository->setup_stage) {
            $repository->update(['setup_stage' => $data['status']]);
        }

        $attributes = ['last_heartbeat_at' => now()];
        if ($data['status'] === $finalStage) {
            $attributes = array_merge($attributes, [
                'status' => Build::STATUS_SUCCEEDED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'built_at' => now(),
                'finished_at' => now(),
            ]);
        }
        $locked->update($attributes);
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.status');

Route::post('builds/{build}/deployment/callback/revision', BuildRevisionCallbackController::class)
    ->middleware('signed')
    ->name('callbacks.build.revision');

Route::post('builds/{build}/deployment/callback/failed', function (Build $build) {
    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);

    DB::transaction(function () use ($build, $data): void {
        $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
        if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
            return;
        }

        $locked->update([
            'status' => Build::STATUS_FAILED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'finished_at' => now(),
            'failure_message' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
        ]);
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.failed');

Route::post('builds/{build}/deployment/callback/log', function (Build $build) {
    DB::transaction(function () use ($build): void {
        $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
        if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
            return;
        }

        $data = request()->validate([
            'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.deployment_log_max_characters'))],
        ]);

        $locked->logs()->updateOrCreate(
            ['type' => Build::DEPLOYMENT_LOG_TYPE],
            ['log' => $data['log']],
        );
        $locked->update(['last_heartbeat_at' => now()]);
    });

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.log');

require __DIR__.'/auth.php';
