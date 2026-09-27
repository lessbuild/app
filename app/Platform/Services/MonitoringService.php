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
    public function key(): string
    {
        return 'monitoring';
    }

    public function name(): string
    {
        return 'Monitoring';
    }

    public function tagline(): string
    {
        return __('Uptime checks, errors, traces and alerts for every environment.');
    }

    public function icon(): string
    {
        return 'check-circle';
    }

    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Monitors'), route('monitoring.monitors', $projectId), 'monitoring.monitors*'),
            new ServiceNavItem(__('Incidents'), route('monitoring.incidents', $projectId), 'monitoring.incidents*'),
            new ServiceNavItem(__('Alert destinations'), route('monitoring.destinations', $projectId), 'monitoring.destinations*'),
            new ServiceNavItem(__('Maintenance'), route('monitoring.maintenance', $projectId), 'monitoring.maintenance*'),
        ];
    }

    public function apiScopes(): array
    {
        return [ApiScope::MonitoringRead, ApiScope::MonitoringWrite];
    }

    public function billing(): ServiceBilling
    {
        return MonitoringCatalog::billing();
    }
}
