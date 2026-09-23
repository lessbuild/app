<?php

namespace App\Modules\Deployer\Providers;

use App\Core\Providers\ModuleServiceProvider;
use App\Core\Services\Identity\MappedProductPrincipalAdapter;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\ProjectProductLinkRegistry;
use App\Core\Services\ProjectProductSummaryRegistry;
use App\Core\Services\ProjectResourceLinkRegistry;
use App\Core\Services\ProjectSetupRegistry;
use App\Modules\Deployer\Contracts\ServerTroubleshootingTransport;
use App\Modules\Deployer\Http\Livewire\BuildDeploymentStatus;
use App\Modules\Deployer\Http\Livewire\RepositoryDeploymentTimeline;
use App\Modules\Deployer\Http\Livewire\ServerCommand;
use App\Modules\Deployer\Http\Livewire\ServerSetup;
use App\Modules\Deployer\Http\Livewire\ServerShow;
use App\Modules\Deployer\Http\Livewire\WebsiteProvisioningLog;
use App\Modules\Deployer\Http\Livewire\WebsiteSetup;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Core\DeployerProjectLink;
use App\Modules\Deployer\Services\Core\DeployerProjectSetup;
use App\Modules\Deployer\Services\Core\DeployerProjectSummary;
use App\Modules\Deployer\Services\Core\DeployerResourceLinkProvider;
use App\Modules\Deployer\Services\DashboardCreationDialogData;
use App\Modules\Deployer\Services\SshServerTroubleshootingTransport;
use App\Modules\Deployer\View\Navigation\WorkspaceNavigation;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewInstance;
use Laravel\Cashier\Cashier;
use Livewire\Livewire;

final class DeployerServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServerTroubleshootingTransport::class, SshServerTroubleshootingTransport::class);
    }

    public function boot(): void
    {
        parent::boot();

        if (! config('platform.products.deployer.enabled', false)) {
            return;
        }

        Cashier::useCustomerModel(User::class);
        app(ProjectProductLinkRegistry::class)->register('deployer', app(DeployerProjectLink::class));
        app(ProjectProductSummaryRegistry::class)->register('deployer', app(DeployerProjectSummary::class));
        app(ProjectResourceLinkRegistry::class)->register('deployer', app(DeployerResourceLinkProvider::class));
        app(ProjectSetupRegistry::class)->register('deployer', app(DeployerProjectSetup::class));
        app(ProductPrincipalRegistry::class)->register(
            'deployer',
            new MappedProductPrincipalAdapter('deployer', User::class, app(LegacyIdentityResolver::class)),
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

    protected function modulePath(): string
    {
        return 'Modules/Deployer';
    }

    protected function moduleKey(): string
    {
        return 'deployer';
    }
}
