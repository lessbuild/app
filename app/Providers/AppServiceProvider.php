<?php

namespace App\Providers;

use App\Contracts\ServerTroubleshootingTransport;
use App\Http\Livewire\BuildDeploymentStatus;
use App\Http\Livewire\RepositoryDeploymentTimeline;
use App\Http\Livewire\ServerCommand;
use App\Http\Livewire\ServerSetup;
use App\Http\Livewire\ServerShow;
use App\Http\Livewire\WebsiteProvisioningLog;
use App\Http\Livewire\WebsiteSetup;
use App\Models\User;
use App\Services\ApplicationTemplateCatalog;
use App\Services\DashboardCreationDialogData;
use App\Services\SshServerTroubleshootingTransport;
use App\View\Navigation\WorkspaceNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;
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
        $this->app->bind(ServerTroubleshootingTransport::class, SshServerTroubleshootingTransport::class);
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
        Livewire::component('repository-deployment-timeline', RepositoryDeploymentTimeline::class);
        Livewire::component('repository-setup', RepositoryDeploymentTimeline::class);
        Livewire::component('server-command', ServerCommand::class);
        Livewire::component('server-setup', ServerSetup::class);
        Livewire::component('server-show', ServerShow::class);
        Livewire::component('website-setup', WebsiteSetup::class);
        Livewire::component('website-provisioning-log', WebsiteProvisioningLog::class);

        View::composer('components.layouts.app', function (ViewInstance $view): void {
            $user = auth()->user();
            $creationDialogData = null;
            $dialog = request()->query('dialog');

            if ($user instanceof User
                && in_array($dialog, ['create-server', 'create-website', 'create-repository'], true)
                && ! request()->routeIs('dashboard', 'providers.index', 'servers.index', 'websites.index', 'repositories.index', 'projects.index')) {
                $creationDialogData = app(DashboardCreationDialogData::class)->for($user);
            }

            $view->with([
                'navigation' => $user instanceof User ? app(WorkspaceNavigation::class)->for($user) : [],
                'applicationCreationTemplates' => $user instanceof User
                    ? app(ApplicationTemplateCatalog::class)->all()
                    : [],
                'creationDialogData' => $creationDialogData,
            ]);
        });
    }
}
