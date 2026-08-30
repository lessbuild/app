<?php

namespace App\Providers;

use App\Http\Livewire\BuildDeploymentStatus;
use App\Http\Livewire\RepositorySetup;
use App\Http\Livewire\ServerCommand;
use App\Http\Livewire\ServerSetup;
use App\Http\Livewire\WebsiteProvisioningLog;
use App\Http\Livewire\WebsiteSetup;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Livewire::component('build-deployment-status', BuildDeploymentStatus::class);
        Livewire::component('repository-setup', RepositorySetup::class);
        Livewire::component('server-command', ServerCommand::class);
        Livewire::component('server-setup', ServerSetup::class);
        Livewire::component('website-setup', WebsiteSetup::class);
        Livewire::component('website-provisioning-log', WebsiteProvisioningLog::class);
    }
}
