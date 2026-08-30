<?php

use App\Http\Controllers\BuildsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RecipesController;
use App\Http\Controllers\RepositoriesController;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebsitesController;
use App\Http\Livewire\ServerShow;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Build;
use App\Models\Server;
use App\Models\Website;
use App\Services\RepositoryDeploymentPlan;
use App\Services\ServerProvisioningPlan;
use App\Services\WebsiteProvisioningPlan;
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

    Route::get('account', [UsersController::class, 'index'])->name('account.index');
    Route::patch('account/profile', [UsersController::class, 'updateProfile'])
        ->name('account.profile.update');
    Route::patch('account/password', [UsersController::class, 'updatePassword'])
        ->name('account.password.update');
    Route::resource('websites', WebsitesController::class);
    Route::post('websites/{website}/placement/cleanup', [WebsitesController::class, 'retryPlacementCleanup'])
        ->name('websites.placement.cleanup');
    Route::post('websites/{website}/provisioning/retry', [WebsitesController::class, 'retryProvisioning'])
        ->name('websites.provisioning.retry');

    Route::resource('servers', ServersController::class)->only(['index', 'create', 'store', 'destroy']);
    Route::get('servers/{server}', ServerShow::class)
        ->middleware('can:view,server')
        ->name('servers.show');
    Route::post('servers/{server}/initialization/retry', [ServersController::class, 'retryInitialization'])
        ->name('servers.initialization.retry');
    Route::post('servers/{server}/provisioning/retry', [ServersController::class, 'retryRemoteProvisioning'])
        ->name('servers.provisioning.retry');

    Route::resource('builds', BuildsController::class)->only(['index', 'show']);
    Route::post('builds/{build}/cancel', [BuildsController::class, 'cancel'])
        ->name('builds.cancel');
    Route::resource('repositories', RepositoriesController::class);
    Route::resource('recipes', RecipesController::class)->except('show');
    Route::resource('providers', ProviderController::class);
    Route::post('repositories/{repository}/deploy', [RepositoriesController::class, 'deploy'])
        ->name('repositories.deploy');
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
        ]);
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.server.failed');

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

Route::post('builds/{build}/deployment/callback/status', function (Build $build) {
    if (! in_array($build->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
        return response()->noContent();
    }

    $finalStage = app(RepositoryDeploymentPlan::class)->finalStage();
    $data = request()->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
    $repository = $build->repository;
    if ($data['status'] > $repository->setup_stage) {
        $repository->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === $finalStage) {
        $build->update([
            'status' => Build::STATUS_SUCCEEDED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'built_at' => now(),
            'finished_at' => now(),
        ]);
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.status');

Route::post('builds/{build}/deployment/callback/failed', function (Build $build) {
    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);

    if (in_array($build->status, [
        Build::STATUS_DEPLOYING,
        Build::STATUS_RUNNING,
    ], true)) {
        $build->update([
            'status' => Build::STATUS_FAILED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'finished_at' => now(),
            'failure_message' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
        ]);
    }

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.failed');

Route::post('builds/{build}/deployment/callback/log', function (Build $build) {
    $data = request()->validate([
        'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.deployment_log_max_characters'))],
    ]);

    $build->logs()->updateOrCreate(
        ['type' => 'deployment'],
        ['log' => $data['log']],
    );

    return response()->noContent();
})->middleware('signed')->name('callbacks.build.log');

require __DIR__.'/auth.php';
