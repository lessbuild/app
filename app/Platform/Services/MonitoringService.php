<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Enums\ApiScope;
use App\Platform\Catalog\MonitoringCatalog;
use App\Platform\Catalog\ServiceBilling;
use App\Platform\PlatformService;
use App\Platform\ServiceNavItem;

/** Uptime, DNS, TLS, TCP, heartbeat and queue monitors with incidents and alerts (ported from the standalone Monitor app). */
final class MonitoringService implements PlatformService
{
    /**
     * Get the service's key, stored on projects and billing items as `monitoring`.
     *
     * @return string
     */
    public function key(): string
    {
        return 'monitoring';
    }

    /**
     * Get the service's name, shown as "Monitoring".
     *
     * @return string
     */
    public function name(): string
    {
        return 'Monitoring';
    }

    /**
     * Describe Monitoring on the service cards.
     *
     * @return string
     */
    public function tagline(): string
    {
        return __('Uptime checks, errors, traces and alerts for every environment.');
    }

    /**
     * Get the service's icon: a check mark, standing for passing checks.
     *
     * @return string
     */
    public function icon(): string
    {
        return 'check-circle';
    }

    /**
     * Get the service's pages: monitors, incidents, issues, telemetry events and traces, metrics and dashboards,
     * releases, SLOs, alerting, status pages and the telemetry setup.
     *
     * @param  string  $projectId
     * @return list<ServiceNavItem>
     */
    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Monitors'), route('monitoring.monitors', $projectId), 'monitoring.monitors*'),
            new ServiceNavItem(__('Incidents'), route('monitoring.incidents', $projectId), 'monitoring.incidents*'),
            new ServiceNavItem(__('Issues'), route('monitoring.issues', $projectId), 'monitoring.issues*'),
            new ServiceNavItem(__('Events'), route('monitoring.events', $projectId), 'monitoring.events*|monitoring.traces*|monitoring.dependencies'),
            new ServiceNavItem(__('Metrics'), route('monitoring.metrics', $projectId), 'monitoring.metrics*|monitoring.dashboards*'),
            new ServiceNavItem(__('Releases'), route('monitoring.releases', $projectId), 'monitoring.releases*|monitoring.deployments*'),
            new ServiceNavItem(__('SLOs'), route('monitoring.objectives', $projectId), 'monitoring.objectives*'),
            new ServiceNavItem(__('Alerts'), route('monitoring.rules', $projectId), 'monitoring.rules*|monitoring.destinations*|monitoring.on-call*|monitoring.maintenance*'),
            new ServiceNavItem(__('Status pages'), route('monitoring.status-pages', $projectId), 'monitoring.status-pages*'),
            new ServiceNavItem(__('Setup'), route('monitoring.setup', $projectId), 'monitoring.setup|monitoring.ingest*'),
        ];
    }

    /**
     * Get the service's API scopes: Monitoring read and write.
     *
     * @return list<ApiScope>
     */
    public function apiScopes(): array
    {
        return [ApiScope::MonitoringRead, ApiScope::MonitoringWrite];
    }

    /**
     * Get Monitor's tiers and usage meters, carried over unchanged.
     *
     * @return ServiceBilling
     */
    public function billing(): ServiceBilling
    {
        return MonitoringCatalog::billing();
    }
}
