<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ApiScope;
use App\Platform\Catalog\DeployCatalog;
use App\Platform\ServiceRegistry;
use App\Platform\Services\AnalyticsService;
use App\Platform\Services\InfrastructureService;
use App\Platform\Services\MonitoringService;
use App\Platform\Services\PlaceholderService;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServiceRegistry::class, function (): ServiceRegistry {
            $registry = new ServiceRegistry;
            // Phase 4 replaces each placeholder with the real service from its own context.
            $registry->register(new PlaceholderService('deploy', 'Deploy', __('Build and release your apps from Git, with previews and rollbacks.'), 'cloud-upload', [ApiScope::DeployRead, ApiScope::DeployWrite], DeployCatalog::billing()));
            $registry->register(new InfrastructureService);
            $registry->register(new MonitoringService);
            $registry->register(new AnalyticsService);

            return $registry;
        });
    }
}
