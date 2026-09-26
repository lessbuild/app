<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Workspace;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;

final class WorkspaceNavigation
{
    public function __construct(
        private readonly Gate $gate,
        private readonly Request $request,
    ) {}

    /** @return array<string, list<array{label: string, href: string, icon: string, active: bool, route: string}>> */
    public function forWorkspace(Workspace $workspace): array
    {
        $navigation = [
            'Workspace' => [
                ['label' => 'Overview', 'route' => 'dashboard', 'icon' => 'grid', 'match' => 'dashboard', 'ability' => 'view'],
                ['label' => 'Dashboards', 'route' => 'dashboards.index', 'icon' => 'grid', 'match' => 'dashboards.*', 'ability' => 'view'],
                ['label' => 'Applications', 'route' => 'applications.index', 'icon' => 'server', 'match' => ['applications.*', 'environments.*'], 'ability' => 'view'],
            ],
            'Investigation' => [
                ['label' => 'Events & logs', 'route' => 'events.index', 'icon' => 'activity', 'match' => ['events.*', 'traces.*'], 'ability' => 'view'],
                ['label' => 'Issues', 'route' => 'issues.index', 'icon' => 'bug', 'match' => 'issues.*', 'ability' => 'view'],
                ['label' => 'Metrics', 'route' => 'metrics.index', 'icon' => 'server', 'match' => 'metrics.*', 'ability' => 'view'],
                ['label' => 'Service map', 'route' => 'dependencies.index', 'icon' => 'server', 'match' => 'dependencies.*', 'ability' => 'view'],
                ['label' => 'Releases', 'route' => 'releases.index', 'icon' => 'code', 'match' => ['releases.*', 'deployments.*'], 'ability' => 'view'],
            ],
            'Reliability' => [
                ['label' => 'Incidents', 'route' => 'incidents.index', 'icon' => 'activity', 'match' => 'incidents.*', 'ability' => 'view'],
                ['label' => 'Monitors', 'route' => 'monitors.index', 'icon' => 'activity', 'match' => 'monitors.*', 'ability' => 'view'],
                ['label' => 'SLOs', 'route' => 'objectives.index', 'icon' => 'shield', 'match' => 'objectives.*', 'ability' => 'view'],
                ['label' => 'Alert rules', 'route' => 'alerts.index', 'icon' => 'shield', 'match' => 'alerts.*', 'ability' => 'view'],
                ['label' => 'Alert destinations', 'route' => 'alert-destinations.index', 'icon' => 'shield', 'match' => ['alert-destinations.*', 'alert-deliveries.*'], 'ability' => 'update'],
                ['label' => 'Status pages', 'route' => 'status-pages.index', 'icon' => 'globe', 'match' => 'status-pages.*', 'ability' => 'view'],
                ['label' => 'Maintenance', 'route' => 'maintenance-windows.index', 'icon' => 'clock', 'match' => 'maintenance-windows.*', 'ability' => 'view'],
            ],
            'Settings' => [
                ['label' => 'Integrations', 'route' => 'settings.integrations', 'icon' => 'code', 'match' => 'settings.integrations', 'ability' => 'update'],
                ['label' => 'API reference', 'route' => 'settings.api', 'icon' => 'code', 'match' => 'settings.api', 'ability' => 'view'],
                ['label' => 'Data & privacy', 'route' => 'settings.data', 'icon' => 'shield', 'match' => 'settings.data*', 'ability' => 'view'],
                ['label' => 'Notifications', 'route' => 'settings.notifications', 'icon' => 'bell', 'match' => 'settings.notifications', 'ability' => 'view'],
                ['label' => 'Team access', 'route' => 'settings.team', 'icon' => 'shield', 'match' => 'settings.team', 'ability' => 'view'],
                ['label' => 'Audit log', 'route' => 'settings.audit-log', 'icon' => 'activity', 'match' => 'settings.audit-log', 'ability' => 'view'],
                ['label' => 'Plans & billing', 'route' => 'settings.billing', 'icon' => 'credit-card', 'match' => 'settings.billing', 'ability' => 'billing'],
            ],
        ];
        $abilities = [];
        $groups = [];

        foreach ($navigation as $group => $items) {
            foreach ($items as $item) {
                $abilities[$item['ability']] ??= $this->gate->allows($item['ability'], $workspace);

                if (! $abilities[$item['ability']]) {
                    continue;
                }

                $route = 'monitor.'.$item['route'];
                $matches = array_map(
                    static fn (string $pattern): string => 'monitor.'.$pattern,
                    (array) $item['match'],
                );

                $groups[$group][] = [
                    'label' => $item['label'],
                    'href' => route($route),
                    'icon' => $item['icon'],
                    'active' => $this->request->routeIs($matches),
                    'route' => $route,
                ];
            }
        }

        return $groups;
    }
}
