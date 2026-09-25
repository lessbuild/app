<?php

namespace App\Modules\Analytics\Providers;

use App\Core\Providers\ModuleServiceProvider;
use App\Core\Services\Blueprints\ProjectBlueprintProviderRegistry;
use App\Core\Services\Connections\ProjectConnectionDeliveryConsumerRegistry;
use App\Core\Services\Deletion\ProductDeletionRegistry;
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
use App\Core\Services\ProjectTrafficContextRegistry;
use App\Core\Services\Search\WorkspaceSearchProviderRegistry;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Core\Services\WorkspaceAnalyticsAdministrationProviderRegistry;
use App\Core\Services\WorkspaceProductUsageProviderRegistry;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\Connections\ConsumeDeployerReleaseAnnotation;
use App\Modules\Analytics\Services\Connections\ConsumeMonitorIncidentAnnotation;
use App\Modules\Analytics\Services\Core\AnalyticsApiDocumentationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsPlatformPrincipalProvisioner;
use App\Modules\Analytics\Services\Core\AnalyticsPlatformStatusProvider;
use App\Modules\Analytics\Services\Core\AnalyticsProductWorkspaceProvisioner;
use App\Modules\Analytics\Services\Core\AnalyticsProjectBlueprintProvider;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSetup;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSummary;
use App\Modules\Analytics\Services\Core\AnalyticsResourceDestinationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsResourceLinkProvider;
use App\Modules\Analytics\Services\Core\AnalyticsTrafficContextProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceActivityProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceDataAdministrationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceGoalAdministrationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceMembershipProjector;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceSearchProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceSiteAdministrationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceUsageProvider;
use App\Modules\Analytics\Services\Deletion\AnalyticsProductDeletionProvider;
use App\Modules\Analytics\Services\WorkspaceViewData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewInstance;

final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(app_path('Modules/Analytics/Config/Source/analytics.php'), 'analytics');
        $this->mergeConfigFrom(app_path('Modules/Analytics/Config/Source/horizon.php'), 'horizon');

        if (! config('platform.products.analytics.enabled', false)
            || ! filled(config('platform.products.analytics.host'))
            || ! config('analytics.horizon_enabled', false)) {
            return;
        }

        if (config('queue.connections.analytics.driver') !== 'redis') {
            throw new \RuntimeException('Analytics Horizon requires ANALYTICS_QUEUE_DRIVER=redis.');
        }

        config([
            'horizon.domain' => config('platform.products.analytics.host'),
            'horizon.use' => 'analytics',
            'horizon.middleware' => ['web', 'auth:platform'],
        ]);

        $this->app->register(\Laravel\Horizon\HorizonServiceProvider::class);
        $this->app->register(HorizonServiceProvider::class);
    }

    public function boot(): void
    {
        parent::boot();

        app(ProductApiDocumentationRegistry::class)->register(
            'analytics',
            app(AnalyticsApiDocumentationProvider::class),
        );

        app(PlatformStatusProviderRegistry::class)->register(
            'analytics',
            app(AnalyticsPlatformStatusProvider::class),
        );
        app(ProjectConnectionDeliveryConsumerRegistry::class)->register(app(ConsumeDeployerReleaseAnnotation::class));
        app(ProjectConnectionDeliveryConsumerRegistry::class)->register(app(ConsumeMonitorIncidentAnnotation::class));
        app(ProductDeletionRegistry::class)->register(app(AnalyticsProductDeletionProvider::class));

        if (! config('platform.products.analytics.enabled', false)
            || ! filled(config('platform.products.analytics.host'))) {
            return;
        }

        app(ProjectProductLinkRegistry::class)->register('analytics', app(AnalyticsProjectLink::class));
        app(ProjectBlueprintProviderRegistry::class)->register('analytics', app(AnalyticsProjectBlueprintProvider::class));
        app(WorkspaceAnalyticsAdministrationProviderRegistry::class)->registerSites(app(AnalyticsWorkspaceSiteAdministrationProvider::class));
        app(WorkspaceAnalyticsAdministrationProviderRegistry::class)->registerGoals(app(AnalyticsWorkspaceGoalAdministrationProvider::class));
        app(WorkspaceAnalyticsAdministrationProviderRegistry::class)->registerData(app(AnalyticsWorkspaceDataAdministrationProvider::class));
        app(ProjectResourceDestinationRegistry::class)->register('analytics', app(AnalyticsResourceDestinationProvider::class));
        app(ProjectProductSummaryRegistry::class)->register('analytics', app(AnalyticsProjectSummary::class));
        app(WorkspaceActivityProviderRegistry::class)->register('analytics', app(AnalyticsWorkspaceActivityProvider::class));
        app(WorkspaceProductUsageProviderRegistry::class)->register('analytics', app(AnalyticsWorkspaceUsageProvider::class));
        app(ProjectTrafficContextRegistry::class)->register('analytics', app(AnalyticsTrafficContextProvider::class));
        app(WorkspaceSearchProviderRegistry::class)->register('analytics', app(AnalyticsWorkspaceSearchProvider::class));
        app(ProjectResourceLinkRegistry::class)->register('analytics', app(AnalyticsResourceLinkProvider::class));
        app(ProjectSetupRegistry::class)->register('analytics', app(AnalyticsProjectSetup::class));
        app(ProductPrincipalRegistry::class)->register(
            'analytics',
            new MappedProductPrincipalAdapter('analytics', User::class, app(LegacyIdentityResolver::class)),
        );
        app(ProductPrincipalProvisionerRegistry::class)->register(
            'analytics',
            app(AnalyticsPlatformPrincipalProvisioner::class),
        );
        app(ProductWorkspaceMembershipProjectorRegistry::class)->register(
            'analytics',
            app(AnalyticsWorkspaceMembershipProjector::class),
        );
        app(ProductWorkspaceProvisionerRegistry::class)->register(
            'analytics',
            app(AnalyticsProductWorkspaceProvisioner::class),
        );

        Gate::policy(Site::class, SitePolicy::class);

        View::composer('analytics::layouts.app', function (ViewInstance $view): void {
            app(WorkspaceViewData::class)->compose($view);
        });

        RateLimiter::for('analytics.collect', function (Request $request): array {
            $limit = config('analytics.collect_rate_per_minute', 120);
            $publicId = (string) $request->route('publicId');

            return [
                Limit::perMinute($limit)->by($request->ip()),
                Limit::perMinute($limit)->by($publicId.'|'.$request->ip()),
            ];
        });
    }

    protected function modulePath(): string
    {
        return 'Modules/Analytics';
    }

    protected function moduleKey(): string
    {
        return 'analytics';
    }

    protected function routeNamePrefix(): string
    {
        return 'analytics.';
    }
}
