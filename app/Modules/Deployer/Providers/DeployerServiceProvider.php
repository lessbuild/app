<?php

namespace App\Modules\Deployer\Providers;

use App\Core\Providers\ModuleServiceProvider;
use App\Core\Services\Connections\ProjectConnectionDiagnosticRegistry;
use App\Core\Services\CustomerStatusPageProviderRegistry;
use App\Core\Services\Identity\MappedProductPrincipalAdapter;
use App\Core\Services\Identity\ProductPrincipalProvisionerRegistry;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\Identity\ProductWorkspaceMembershipProjectorRegistry;
use App\Core\Services\Identity\ProductWorkspaceProvisionerRegistry;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\PlatformStatusProviderRegistry;
use App\Core\Services\ProductApiDocumentationRegistry;
use App\Core\Services\ProjectProductLinkRegistry;
use App\Core\Services\ProjectProductSummaryRegistry;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceLinkRegistry;
use App\Core\Services\ProjectSetupRegistry;
use App\Core\Services\ResolveSharedProjectContextForRequest;
use App\Core\Services\Search\WorkspaceSearchProviderRegistry;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Core\Services\WorkspaceCostBreakdownProviderRegistry;
use App\Core\Services\WorkspaceCredentialProviderRegistry;
use App\Core\Services\WorkspaceCustomerStatusManagementProviderRegistry;
use App\Core\Services\WorkspaceFeedbackHistoryProviderRegistry;
use App\Core\Services\WorkspaceWebhookDeliveryProviderRegistry;
use App\Modules\Deployer\Contracts\ServerTroubleshootingTransport;
use App\Modules\Deployer\Http\Livewire\BuildDeploymentStatus;
use App\Modules\Deployer\Http\Livewire\RepositoryDeploymentTimeline;
use App\Modules\Deployer\Http\Livewire\ServerCommand;
use App\Modules\Deployer\Http\Livewire\ServerSetup;
use App\Modules\Deployer\Http\Livewire\ServerShow;
use App\Modules\Deployer\Http\Livewire\WebsiteProvisioningLog;
use App\Modules\Deployer\Http\Livewire\WebsiteSetup;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Core\DeployerApiDocumentationProvider;
use App\Modules\Deployer\Services\Core\DeployerCustomerStatusPageProvider;
use App\Modules\Deployer\Services\Core\DeployerPlatformPrincipalProvisioner;
use App\Modules\Deployer\Services\Core\DeployerPlatformStatusProvider;
use App\Modules\Deployer\Services\Core\DeployerProductWorkspaceProvisioner;
use App\Modules\Deployer\Services\Core\DeployerProjectConnectionDiagnosticProvider;
use App\Modules\Deployer\Services\Core\DeployerProjectLink;
use App\Modules\Deployer\Services\Core\DeployerProjectSetup;
use App\Modules\Deployer\Services\Core\DeployerProjectSummary;
use App\Modules\Deployer\Services\Core\DeployerResourceDestinationProvider;
use App\Modules\Deployer\Services\Core\DeployerResourceLinkProvider;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceActivityProvider;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceCostBreakdownProvider;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceCredentialProvider;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceCustomerStatusManagementProvider;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceFeedbackHistoryProvider;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceMembershipProjector;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceSearchProvider;
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
        app(ProductApiDocumentationRegistry::class)->register('deployer', app(DeployerApiDocumentationProvider::class));
        app(ProjectProductLinkRegistry::class)->register('deployer', app(DeployerProjectLink::class));
        app(ProjectConnectionDiagnosticRegistry::class)->register('deployer', app(DeployerProjectConnectionDiagnosticProvider::class));
        app(ProjectResourceDestinationRegistry::class)->register('deployer', app(DeployerResourceDestinationProvider::class));
        app(ProjectProductSummaryRegistry::class)->register('deployer', app(DeployerProjectSummary::class));
        app(WorkspaceActivityProviderRegistry::class)->register('deployer', app(DeployerWorkspaceActivityProvider::class));
        app(WorkspaceCredentialProviderRegistry::class)->register('deployer', app(DeployerWorkspaceCredentialProvider::class));
        app(WorkspaceWebhookDeliveryProviderRegistry::class)->register('deployer', app(DeployerWorkspaceActivityProvider::class));
        app(WorkspaceCostBreakdownProviderRegistry::class)->register('deployer', app(DeployerWorkspaceCostBreakdownProvider::class));
        app(WorkspaceCustomerStatusManagementProviderRegistry::class)->register('deployer', app(DeployerWorkspaceCustomerStatusManagementProvider::class));
        app(WorkspaceFeedbackHistoryProviderRegistry::class)->register('deployer', app(DeployerWorkspaceFeedbackHistoryProvider::class));
        app(CustomerStatusPageProviderRegistry::class)->register('deployer', app(DeployerCustomerStatusPageProvider::class));
        app(PlatformStatusProviderRegistry::class)->register('deployer', app(DeployerPlatformStatusProvider::class));
        app(ProjectResourceLinkRegistry::class)->register('deployer', app(DeployerResourceLinkProvider::class));
        app(ProjectSetupRegistry::class)->register('deployer', app(DeployerProjectSetup::class));
        app(WorkspaceSearchProviderRegistry::class)->register('deployer', app(DeployerWorkspaceSearchProvider::class));
        app(ProductPrincipalRegistry::class)->register(
            'deployer',
            new MappedProductPrincipalAdapter('deployer', User::class, app(LegacyIdentityResolver::class)),
        );
        app(ProductPrincipalProvisionerRegistry::class)->register(
            'deployer',
            app(DeployerPlatformPrincipalProvisioner::class),
        );
        app(ProductWorkspaceMembershipProjectorRegistry::class)->register(
            'deployer',
            app(DeployerWorkspaceMembershipProjector::class),
        );
        app(ProductWorkspaceProvisionerRegistry::class)->register(
            'deployer',
            app(DeployerProductWorkspaceProvisioner::class),
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

            $environment = request()->route('environment');
            $project = request()->route('project');
            $sourceResourceType = $environment instanceof Environment
                ? 'environment'
                : ($project instanceof Project ? 'project' : null);
            $sourceResourceId = $environment instanceof Environment
                ? $environment->getKey()
                : ($project instanceof Project ? $project->getKey() : null);
            $sharedProjectContext = app(ResolveSharedProjectContextForRequest::class)->handle(
                request(),
                'deployer',
                $sourceResourceType,
                $sourceResourceId,
            );

            $view->with([
                'navigation' => $user instanceof User ? app(WorkspaceNavigation::class)->for($user) : [],
                'applicationCreationTemplates' => $user instanceof User
                    ? app(ApplicationTemplateCatalog::class)->all()
                    : [],
                'creationDialogData' => $creationDialogData,
                'productUrlOverrides' => $sharedProjectContext->isAvailable() ? $sharedProjectContext->productUrlOverrides : null,
                'sharedContextUnavailable' => $sharedProjectContext->isUnavailable(),
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
