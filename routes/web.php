<?php

use App\Http\Controllers\BuildsController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RepositoriesController;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebsitesController;
use App\Http\Livewire\ServerShow;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

    Route::get('home', function () {
        return view('welcome');
    })->name('dashboard');

    Route::resource('users', UsersController::class);
    Route::resource('websites', WebsitesController::class);

    Route::resource('servers', ServersController::class);
    Route::get('servers/{server}', ServerShow::class)->name('servers.show');

    Route::resource('builds', BuildsController::class);
    Route::resource('repositories', RepositoriesController::class);
    Route::resource('providers', ProviderController::class);
    Route::get('make', [ServersController::class, 'make']);

    Route::get('repositories/deploy/{repository}', [RepositoriesController::class, 'deploy'])
        ->name('repositories.deploy');
});

Route::post('servers/provisioning/callback/status', function () {
    $server = Server::find(request()->input('server_id'));
    $step = request()->input('status');
    $server->update([
        'setup_stage' => $step,
    ]);
});

Route::post('servers/add-website/callback/status', function () {
    $website = Website::find(request()->input('website_id'));
    $step = request()->input('status');
    $website->update([
        'setup_stage' => $step,
    ]);
});

Route::post('servers/release-repository/callback/status', function () {
    $repository = Repository::find(request()->input('repository_id'));
    $step = request()->input('status');
    $repository->update([
        'setup_stage' => $step,
    ]);
});

require __DIR__.'/auth.php';
