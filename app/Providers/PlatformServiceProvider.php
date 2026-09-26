<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Api\Enums\ApiScope;
use App\Platform\ServiceRegistry;
use App\Platform\Services\PlaceholderService;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServiceRegistry::class, function (): ServiceRegistry {
            $registry = new ServiceRegistry;
            // Phase 4 replaces each placeholder with the real service from its own context.
            $registry->register(new PlaceholderService('deploy', 'Deploy', __('Build and release your apps from Git, with previews and rollbacks.'), 'cloud-upload', [ApiScope::DeployRead, ApiScope::DeployWrite]));
            $registry->register(new PlaceholderService('infrastructure', 'Infrastructure', __('Servers, databases, domains and backups on the providers you choose.'), 'server', [ApiScope::InfrastructureRead, ApiScope::InfrastructureWrite]));
            $registry->register(new PlaceholderService('monitoring', 'Monitoring', __('Uptime checks, errors, traces and alerts for every environment.'), 'check-circle', [ApiScope::MonitoringRead, ApiScope::MonitoringWrite]));
            $registry->register(new PlaceholderService('analytics', 'Analytics', __('Privacy-friendly traffic analytics, goals and reports.'), 'view-grid', [ApiScope::AnalyticsRead, ApiScope::AnalyticsWrite]));

            return $registry;
        });
    }
}
