<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\ServiceRegistry;
use App\Platform\Services\AnalyticsService;
use App\Platform\Services\DeployService;
use App\Platform\Services\InfrastructureService;
use App\Platform\Services\MonitoringService;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    /**
     * Register the service registry as a singleton holding Deploy, Infrastructure, Monitoring and Analytics, in the
     * order the shell and billing pages list them.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ServiceRegistry::class, function (): ServiceRegistry {
            $registry = new ServiceRegistry;
            $registry->register(new DeployService);
            $registry->register(new InfrastructureService);
            $registry->register(new MonitoringService);
            $registry->register(new AnalyticsService);

            return $registry;
        });
    }
}
