<?php

use App\Http\Controllers\BuildsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RepositoriesController;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebsitesController;
use App\Http\Livewire\ServerShow;
use App\Models\Build;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
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

    Route::resource('servers', ServersController::class)->only(['index', 'create', 'store', 'destroy']);
    Route::get('servers/{server}', ServerShow::class)
        ->middleware('can:view,server')
        ->name('servers.show');

    Route::resource('builds', BuildsController::class)->only('index');
    Route::resource('repositories', RepositoriesController::class);
    Route::resource('providers', ProviderController::class);
    Route::post('repositories/{repository}/deploy', [RepositoriesController::class, 'deploy'])
        ->name('repositories.deploy');
});

Route::post('servers/{server}/provisioning/callback/status', function (Server $server) {
    $data = request()->validate(['status' => 'required|integer|min:0|max:12']);
    if ($data['status'] > $server->setup_stage) {
        $server->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === 12) {
        $server->update([
            'provisioning_status' => Server::STATUS_ACTIVE,
            'provisioned_at' => now(),
            'provisioning_error' => null,
        ]);
    }
})->middleware('signed')->name('callbacks.server');

Route::post('websites/{website}/provisioning/callback/status', function (Website $website) {
    $data = request()->validate(['status' => 'required|integer|min:0|max:3']);
    if ($data['status'] > $website->setup_stage) {
        $website->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === 3) {
        $website->update([
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now(),
            'provisioning_error' => null,
        ]);
    }
})->middleware('signed')->name('callbacks.website');

Route::post('servers/{server}/provisioning/callback/failed', function (Server $server) {
    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);
    $server->update([
        'provisioning_status' => Server::STATUS_FAILED,
        'provisioning_error' => isset($data['exit_code'])
            ? "{$data['message']} (exit code {$data['exit_code']})"
            : $data['message'],
    ]);
})->middleware('signed')->name('callbacks.server.failed');

Route::post('websites/{website}/provisioning/callback/failed', function (Website $website) {
    $data = request()->validate([
        'exit_code' => 'nullable|integer',
        'message' => 'required|string|max:2000',
    ]);
    $website->update([
        'provisioning_status' => Website::STATUS_FAILED,
        'provisioning_error' => isset($data['exit_code'])
            ? "{$data['message']} (exit code {$data['exit_code']})"
            : $data['message'],
    ]);
})->middleware('signed')->name('callbacks.website.failed');

Route::post('repositories/{repository}/deployment/callback/status', function (Repository $repository) {
    $data = request()->validate(['status' => 'required|integer|min:0|max:7']);
    if ($data['status'] > $repository->setup_stage) {
        $repository->update(['setup_stage' => $data['status']]);
    }

    if ($data['status'] === 7) {
        $repository->builds()
            ->whereIn('status', [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING])
            ->latest()
            ->first()
            ?->update([
                'status' => Build::STATUS_SUCCEEDED,
                'built_at' => now(),
                'finished_at' => now(),
            ]);
    }
})->middleware('signed')->name('callbacks.repository');

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
            'finished_at' => now(),
            'failure_message' => isset($data['exit_code'])
                ? "{$data['message']} (exit code {$data['exit_code']})"
                : $data['message'],
        ]);
    }
})->middleware('signed')->name('callbacks.build.failed');

require __DIR__.'/auth.php';
