<?php

namespace App\Providers;

use App\Http\Livewire\BuildDeploymentStatus;
use App\Http\Livewire\RepositorySetup;
use App\Http\Livewire\ServerCommand;
use App\Http\Livewire\ServerSetup;
use App\Http\Livewire\ServerShow;
use App\Http\Livewire\WebsiteProvisioningLog;
use App\Http\Livewire\WebsiteSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(User::class);

        DB::prohibitDestructiveCommands(
            (bool) config('lessbuild.prohibit_destructive_database_commands'),
        );

        Livewire::component('build-deployment-status', BuildDeploymentStatus::class);
        Livewire::component('repository-setup', RepositorySetup::class);
        Livewire::component('server-command', ServerCommand::class);
        Livewire::component('server-setup', ServerSetup::class);
        Livewire::component('server-show', ServerShow::class);
        Livewire::component('website-setup', WebsiteSetup::class);
        Livewire::component('website-provisioning-log', WebsiteProvisioningLog::class);
    }
}
