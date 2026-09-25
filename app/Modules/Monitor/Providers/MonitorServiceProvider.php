<?php

namespace App\Modules\Monitor\Providers;

use App\Core\Providers\ModuleServiceProvider;
use App\Core\Services\Connections\ProjectConnectionDeliveryConsumerRegistry;
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
use App\Core\Services\Search\WorkspaceSearchProviderRegistry;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Core\Services\WorkspaceCredentialProviderRegistry;
use App\Core\Services\WorkspaceMonitorStatusManagementProviderRegistry;
use App\Core\Services\WorkspaceWebhookDeliveryProviderRegistry;
use App\Modules\Monitor\Contracts\DnsRecordResolver;
use App\Modules\Monitor\Contracts\DnsResolver;
use App\Modules\Monitor\Contracts\TcpConnector;
use App\Modules\Monitor\Contracts\TelemetryIngestor;
use App\Modules\Monitor\Contracts\TelemetryPayloadMapper;
use App\Modules\Monitor\Contracts\TlsCertificateInspector;
use App\Modules\Monitor\Http\Middleware\AuthenticateIngestToken;
use App\Modules\Monitor\Http\Middleware\EnsureApplicationWorkspace;
use App\Modules\Monitor\Http\Middleware\RequireWorkspace;
use App\Modules\Monitor\Listeners\CheckApplicationHealth;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\Connections\ConsumeDeploymentSucceeded;
use App\Modules\Monitor\Services\Core\MonitorApiDocumentationProvider;
use App\Modules\Monitor\Services\Core\MonitorCustomerStatusPageProvider;
use App\Modules\Monitor\Services\Core\MonitorPlatformPrincipalProvisioner;
use App\Modules\Monitor\Services\Core\MonitorPlatformStatusProvider;
use App\Modules\Monitor\Services\Core\MonitorProductWorkspaceProvisioner;
use App\Modules\Monitor\Services\Core\MonitorProjectConnectionDiagnosticProvider;
use App\Modules\Monitor\Services\Core\MonitorProjectLink;
use App\Modules\Monitor\Services\Core\MonitorProjectSetup;
use App\Modules\Monitor\Services\Core\MonitorProjectSummary;
use App\Modules\Monitor\Services\Core\MonitorResourceDestinationProvider;
use App\Modules\Monitor\Services\Core\MonitorResourceLinkProvider;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceActivityProvider;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceCredentialProvider;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceMembershipProjector;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceSearchProvider;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceStatusManagementProvider;
use App\Modules\Monitor\Services\DatabaseTelemetryIngestor;
use App\Modules\Monitor\Services\MonitorPublicApiLimits;
use App\Modules\Monitor\Services\NativeDnsRecordResolver;
use App\Modules\Monitor\Services\NativeDnsResolver;
use App\Modules\Monitor\Services\NativeTcpConnector;
use App\Modules\Monitor\Services\NativeTlsCertificateInspector;
use App\Modules\Monitor\Services\Telemetry\OtlpPayloadMapper;
use App\Modules\Monitor\Services\WorkspaceViewData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewInstance;

final class MonitorServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(app_path('Modules/Monitor/Config/beacon.php'), 'monitor.beacon');

        Factory::guessFactoryNamesUsing(static function (string $model): string {
            $monitorPrefix = 'App\\Modules\\Monitor\\Models\\';

            if (str_starts_with($model, $monitorPrefix)) {
                $factory = 'App\\Modules\\Monitor\\Database\\Factories\\'.substr($model, strlen($monitorPrefix)).'Factory';

                if (class_exists($factory)) {
                    $factory::guessModelNamesUsing(static fn (Factory $instance): string => $monitorPrefix.substr(class_basename($instance), 0, -strlen('Factory'))
                    );

                    return $factory;
                }
            }

            $modelName = str_starts_with($model, 'App\\Models\\')
                ? substr($model, strlen('App\\Models\\'))
                : (str_starts_with($model, 'App\\') ? substr($model, strlen('App\\')) : $model);

            return 'Database\\Factories\\'.$modelName.'Factory';
        });

        $this->app->bind(TelemetryIngestor::class, DatabaseTelemetryIngestor::class);
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(DnsRecordResolver::class, NativeDnsRecordResolver::class);
        $this->app->bind(TlsCertificateInspector::class, NativeTlsCertificateInspector::class);
        $this->app->bind(TcpConnector::class, NativeTcpConnector::class);
        $this->app->bind(TelemetryPayloadMapper::class, OtlpPayloadMapper::class);
    }

    public function boot(): void
    {
        parent::boot();

        app(ProductApiDocumentationRegistry::class)->register(
            'monitor',
            app(MonitorApiDocumentationProvider::class),
        );
        app(CustomerStatusPageProviderRegistry::class)->register(
            'monitor',
            app(MonitorCustomerStatusPageProvider::class),
        );
        app(WorkspaceMonitorStatusManagementProviderRegistry::class)->register(
            'monitor',
            app(MonitorWorkspaceStatusManagementProvider::class),
        );
        app(PlatformStatusProviderRegistry::class)->register(
            'monitor',
            app(MonitorPlatformStatusProvider::class),
        );
        app(ProjectConnectionDeliveryConsumerRegistry::class)->register(app(ConsumeDeploymentSucceeded::class));

        if (! config('platform.products.monitor.enabled', false)
            || ! filled(config('platform.products.monitor.host'))) {
            return;
        }

        app(ProjectProductLinkRegistry::class)->register('monitor', app(MonitorProjectLink::class));
        app(ProjectConnectionDiagnosticRegistry::class)->register('monitor', app(MonitorProjectConnectionDiagnosticProvider::class));
        app(ProjectResourceDestinationRegistry::class)->register('monitor', app(MonitorResourceDestinationProvider::class));
        app(ProjectProductSummaryRegistry::class)->register('monitor', app(MonitorProjectSummary::class));
        app(ProjectResourceLinkRegistry::class)->register('monitor', app(MonitorResourceLinkProvider::class));
        app(ProjectSetupRegistry::class)->register('monitor', app(MonitorProjectSetup::class));
        app(WorkspaceSearchProviderRegistry::class)->register('monitor', app(MonitorWorkspaceSearchProvider::class));
        app(WorkspaceActivityProviderRegistry::class)->register('monitor', app(MonitorWorkspaceActivityProvider::class));
        app(WorkspaceCredentialProviderRegistry::class)->register('monitor', app(MonitorWorkspaceCredentialProvider::class));
        app(WorkspaceWebhookDeliveryProviderRegistry::class)->register('monitor', app(MonitorWorkspaceActivityProvider::class));
        app(ProductPrincipalRegistry::class)->register(
            'monitor',
            new MappedProductPrincipalAdapter('monitor', User::class, app(LegacyIdentityResolver::class)),
        );
        app(ProductPrincipalProvisionerRegistry::class)->register(
            'monitor',
            app(MonitorPlatformPrincipalProvisioner::class),
        );
        app(ProductWorkspaceMembershipProjectorRegistry::class)->register(
            'monitor',
            app(MonitorWorkspaceMembershipProjector::class),
        );
        app(ProductWorkspaceProvisionerRegistry::class)->register(
            'monitor',
            app(MonitorProductWorkspaceProvisioner::class),
        );

        app('router')->aliasMiddleware('monitor.ingest.token', AuthenticateIngestToken::class);
        app('router')->aliasMiddleware('monitor.workspace', RequireWorkspace::class);
        app('router')->aliasMiddleware('monitor.application.workspace', EnsureApplicationWorkspace::class);

        foreach (glob(app_path('Modules/Monitor/Models/*.php')) ?: [] as $modelFile) {
            $model = 'App\\Modules\\Monitor\\Models\\'.pathinfo($modelFile, PATHINFO_FILENAME);
            $policy = 'App\\Modules\\Monitor\\Policies\\'.pathinfo($modelFile, PATHINFO_FILENAME).'Policy';

            if (class_exists($model) && class_exists($policy)) {
                Gate::policy($model, $policy);
            }
        }

        View::composer('monitor::layouts.app', function (ViewInstance $view): void {
            app(WorkspaceViewData::class)->compose($view);
        });
        Event::listen(DiagnosingHealth::class, CheckApplicationHealth::class);

        RateLimiter::for('monitor.login', function (Request $request): array {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';

            return [
                Limit::perMinute(5)->by('login:'.hash('sha256', $email.'|'.$request->ip())),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('monitor.account-email', fn (Request $request) => Limit::perMinute(3)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('monitor.registration', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('monitor.heartbeat-ingress', fn (Request $request): Limit => Limit::perMinute(MonitorPublicApiLimits::HEARTBEAT_IP_PER_MINUTE)->by('heartbeat-ip:'.$request->ip()));
        RateLimiter::for('monitor.heartbeats', fn (Request $request): Limit => Limit::perMinute(MonitorPublicApiLimits::HEARTBEAT_MONITOR_PER_MINUTE)->by('heartbeat-monitor:'.$request->attributes->get('heartbeat_monitor_id')));
        RateLimiter::for('monitor.queue-ingress', fn (Request $request): Limit => Limit::perMinute(MonitorPublicApiLimits::QUEUE_IP_PER_MINUTE)->by('queue-ip:'.$request->ip()));
        RateLimiter::for('monitor.queue-signals', fn (Request $request): Limit => Limit::perMinute($request->routeIs('monitor.api.queues.snapshots.store')
            ? MonitorPublicApiLimits::QUEUE_SNAPSHOT_MONITOR_PER_MINUTE
            : MonitorPublicApiLimits::QUEUE_WORKER_MONITOR_PER_MINUTE)
            ->by('queue-monitor:'.$request->attributes->get('queue_monitor_id').':'.$request->route()->getName()));
        RateLimiter::for('monitor.ingest', function (Request $request): Limit {
            $token = $request->bearerToken() ?? $request->header('X-Beacon-Token');

            return Limit::perMinute(MonitorPublicApiLimits::INGEST_TOKEN_PER_MINUTE)->by(is_string($token) ? hash('sha256', $token) : $request->ip());
        });
        RateLimiter::for('monitor.deployments', function (Request $request): Limit {
            $token = $request->bearerToken() ?? $request->header('X-Beacon-Token');

            return Limit::perMinute(MonitorPublicApiLimits::DEPLOYMENT_TOKEN_PER_MINUTE)->by(is_string($token) ? hash('sha256', $token) : $request->ip());
        });
    }

    protected function modulePath(): string
    {
        return 'Modules/Monitor';
    }

    protected function moduleKey(): string
    {
        return 'monitor';
    }

    protected function routeNamePrefix(): string
    {
        return 'monitor.';
    }
}
