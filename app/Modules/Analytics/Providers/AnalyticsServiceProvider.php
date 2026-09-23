<?php

namespace App\Modules\Analytics\Providers;

use App\Core\Providers\ModuleServiceProvider;
use App\Core\Services\Identity\MappedProductPrincipalAdapter;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\ProjectProductLinkRegistry;
use App\Core\Services\ProjectProductSummaryRegistry;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceLinkRegistry;
use App\Core\Services\ProjectSetupRegistry;
use App\Core\Services\ProjectTrafficContextRegistry;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSetup;
use App\Modules\Analytics\Services\Core\AnalyticsProjectSummary;
use App\Modules\Analytics\Services\Core\AnalyticsResourceDestinationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsResourceLinkProvider;
use App\Modules\Analytics\Services\Core\AnalyticsTrafficContextProvider;
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

        if (! config('platform.products.analytics.enabled', false)
            || ! filled(config('platform.products.analytics.host'))) {
            return;
        }

        app(ProjectProductLinkRegistry::class)->register('analytics', app(AnalyticsProjectLink::class));
        app(ProjectResourceDestinationRegistry::class)->register('analytics', app(AnalyticsResourceDestinationProvider::class));
        app(ProjectProductSummaryRegistry::class)->register('analytics', app(AnalyticsProjectSummary::class));
        app(ProjectTrafficContextRegistry::class)->register('analytics', app(AnalyticsTrafficContextProvider::class));
        app(ProjectResourceLinkRegistry::class)->register('analytics', app(AnalyticsResourceLinkProvider::class));
        app(ProjectSetupRegistry::class)->register('analytics', app(AnalyticsProjectSetup::class));
        app(ProductPrincipalRegistry::class)->register(
            'analytics',
            new MappedProductPrincipalAdapter('analytics', User::class, app(LegacyIdentityResolver::class)),
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
