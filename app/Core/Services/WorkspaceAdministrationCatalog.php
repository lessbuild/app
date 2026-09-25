<?php

namespace App\Core\Services;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/** Provides one authorized source for workspace administration links and search actions. */
final class WorkspaceAdministrationCatalog
{
    /** @var array<string, list<array{label: string, description: string, route: string}>> */
    private const MODULE_TOOLS = [
        'deployer' => [
            ['label' => 'Organization settings', 'description' => 'Security rules, notification preferences, SSO, and organization access.', 'route' => 'organizations.index'],
            ['label' => 'Applications and environments', 'description' => 'Configure runtime, secrets, processes, and release controls.', 'route' => 'projects.index'],
            ['label' => 'Repositories and deployments', 'description' => 'Connect source repositories and inspect releases.', 'route' => 'repositories.index'],
            ['label' => 'Builds and release history', 'description' => 'Review deployment runs, logs, comparisons, and release outcomes.', 'route' => 'builds.index'],
            ['label' => 'Providers and servers', 'description' => 'Manage cloud credentials, servers, and provisioning.', 'route' => 'providers.index'],
            ['label' => 'Server fleet and imports', 'description' => 'Review servers, import assessments, provisioning, logs, commands, and access diagnostics.', 'route' => 'servers.index'],
            ['label' => 'Websites, imports, and checks', 'description' => 'Configure deployed sites, imports, TLS, runtime logs, and health checks.', 'route' => 'websites.index'],
            ['label' => 'Domains and DNS', 'description' => 'Manage domains and DNS records connected to Deployer sites.', 'route' => 'domains.index'],
            ['label' => 'Databases and data services', 'description' => 'Manage database resources, users, cloning, and data-service access.', 'route' => 'databases.index'],
            ['label' => 'Backups and recovery', 'description' => 'Manage backup destinations, schedules, verification, and restores.', 'route' => 'backups.index'],
            ['label' => 'High availability', 'description' => 'Configure load balancers and traffic distribution.', 'route' => 'load-balancers.index'],
            ['label' => 'Costs, estimates, and budgets', 'description' => 'Review infrastructure cost estimates, usage, and budget settings.', 'route' => 'core.workspace.costs'],
            ['label' => 'Automation, schedules, and API', 'description' => 'Manage workflows, scheduled deployments/tasks, scaling, and automation tokens.', 'route' => 'automation.index'],
            ['label' => 'Commands, recipes, and templates', 'description' => 'Review operational commands, reusable recipes, and deployment templates.', 'route' => 'commands.index'],
            ['label' => 'Deployment recipes', 'description' => 'Create and manage reusable deployment recipes.', 'route' => 'recipes.index'],
            ['label' => 'Template gallery', 'description' => 'Browse, compare, and manage reusable configuration recipes.', 'route' => 'gallery.index'],
            ['label' => 'Activity history', 'description' => 'Review recent builds, infrastructure changes, and workspace activity.', 'route' => 'core.workspace.workflows'],
            ['label' => 'Notification center', 'description' => 'Review and configure Deployer notifications.', 'route' => 'core.workspace.notifications'],
            ['label' => 'System health', 'description' => 'Deployer runtime and infrastructure diagnostics.', 'route' => 'system-health.index'],
            ['label' => 'Observability', 'description' => 'Operational investigations, incidents, and service status.', 'route' => 'observability.index'],
            ['label' => 'GitHub App setup', 'description' => 'Repository integration and application credentials.', 'route' => 'admin.github-app.setup'],
            ['label' => 'Deployer billing', 'description' => 'Manage the workspace’s independent Deployer plan, invoices, and payments.', 'route' => 'core.workspace.subscriptions'],
            ['label' => 'Deployer account security', 'description' => 'Review Deployer account profile and sign-in security settings.', 'route' => 'platform.account.security'],
            ['label' => 'Feedback review', 'description' => 'Review existing Deployer feedback while its records remain in the Deployer database.', 'route' => 'core.workspace.feedback.index'],
        ],
        'monitor' => [
            ['label' => 'Monitor overview', 'description' => 'Review current service health, incidents, and telemetry activity.', 'route' => 'monitor.dashboard'],
            ['label' => 'Applications, environments, and ingestion', 'description' => 'Manage monitored services, collection credentials, environments, and ingestion setup.', 'route' => 'monitor.applications.index'],
            ['label' => 'Dashboards and reports', 'description' => 'Create dashboards and review saved reliability views.', 'route' => 'monitor.dashboards.index'],
            ['label' => 'Telemetry events', 'description' => 'Search received application events and inspect event payload context.', 'route' => 'monitor.events.index'],
            ['label' => 'Issues and errors', 'description' => 'Review grouped errors and telemetry issues.', 'route' => 'monitor.issues.index'],
            ['label' => 'Metrics and traces', 'description' => 'Explore time series, trace details, and related telemetry.', 'route' => 'monitor.metrics.index'],
            ['label' => 'Service dependencies', 'description' => 'Review discovered service relationships and dependencies.', 'route' => 'monitor.dependencies.index'],
            ['label' => 'Active incidents', 'description' => 'Investigate incidents and review their impact on service health.', 'route' => 'monitor.incidents.index'],
            ['label' => 'Deployments and releases', 'description' => 'Review release events and correlate deployments with service health.', 'route' => 'monitor.releases.index'],
            ['label' => 'Status pages', 'description' => 'Create and publish customer-facing Monitor status pages.', 'route' => 'core.workspace.monitor-status-pages.index'],
            ['label' => 'Alert destinations', 'description' => 'Manage notification targets and delivery history.', 'route' => 'core.workspace.monitor.destinations'],
            ['label' => 'Alert rules', 'description' => 'Tune alert conditions and escalation routing.', 'route' => 'core.workspace.monitor.alerts'],
            ['label' => 'Monitors and checks', 'description' => 'Configure uptime and scheduled health checks.', 'route' => 'monitor.monitors.index'],
            ['label' => 'Service objectives', 'description' => 'Set service-level objectives and review reliability targets.', 'route' => 'monitor.objectives.index'],
            ['label' => 'Maintenance windows', 'description' => 'Schedule planned maintenance and alert suppression.', 'route' => 'monitor.maintenance-windows.index'],
            ['label' => 'Integrations', 'description' => 'Connect telemetry and external services.', 'route' => 'core.workspace.monitor.integrations'],
            ['label' => 'Data and privacy', 'description' => 'Review Monitor data controls and exports.', 'route' => 'core.workspace.monitor.settings'],
            ['label' => 'Notifications', 'description' => 'Configure workspace notification preferences.', 'route' => 'core.workspace.monitor.settings'],
            ['label' => 'Audit log', 'description' => 'Review Monitor workspace changes.', 'route' => 'core.workspace.monitor.audit'],
            ['label' => 'Team settings', 'description' => 'Manage the Monitor workspace team.', 'route' => 'core.workspace.team.index'],
            ['label' => 'Monitor billing', 'description' => 'Manage the workspace’s independent Monitor plan, usage, and payments.', 'route' => 'core.workspace.subscriptions'],
        ],
        'analytics' => [
            ['label' => 'Sites and reporting', 'description' => 'Choose an Analytics site, review traffic reports, and manage report filters.', 'route' => 'core.workspace.analytics.sites.index'],
            ['label' => 'Site setup and tracking', 'description' => 'Add a site, verify its domain, and install the event collector.', 'route' => 'core.workspace.analytics.sites.index'],
            ['label' => 'Goals and conversions', 'description' => 'Choose a site to manage path/event goals and conversion reporting.', 'route' => 'core.workspace.analytics.sites.index'],
            ['label' => 'Site settings and data controls', 'description' => 'Choose a site to manage privacy, collection, retention, and export settings.', 'route' => 'core.workspace.analytics.sites.index'],
            ['label' => 'Exports and processing history', 'description' => 'Review site-scoped report exports and event processing.', 'route' => 'core.workspace.analytics.data.index'],
            ['label' => 'Analytics workspaces and team', 'description' => 'Review Analytics workspace memberships and invitations.', 'route' => 'core.workspace.team.index'],
            ['label' => 'Account settings and security', 'description' => 'Manage Analytics profile and account preferences.', 'route' => 'platform.account.security'],
        ],
    ];

    public function __construct(
        private readonly WorkspaceProjectAccess $access,
        private readonly PlatformProductRouteLinks $links,
    ) {}

    /** @return array<string, Collection<int, array{label: string, description: string, route: string, href: string}>> */
    public function forWorkspace(PlatformUser $user, Workspace $workspace): array
    {
        $membership = $this->access->activeMembership($user, $workspace);

        return $membership === null ? [] : $this->forMembership($membership);
    }

    /** @return array<string, Collection<int, array{label: string, description: string, route: string, href: string}>> */
    public function forMembership(WorkspaceMembership $membership): array
    {
        if (! $membership->currentlyActive()) {
            return [];
        }

        $moduleTools = [];

        foreach (self::MODULE_TOOLS as $product => $tools) {
            if (! config('platform.products.'.$product.'.enabled', false) || ! $this->access->hasProductAccess($membership, $product)) {
                continue;
            }

            $moduleTools[$product] = collect($tools)->map(function (array $tool) use ($product, $membership): ?array {
                $coreRoute = str_starts_with($tool['route'], 'core.') || str_starts_with($tool['route'], 'platform.');
                $href = $coreRoute
                    ? (Route::has($tool['route']) ? route($tool['route'], str_starts_with($tool['route'], 'core.workspace.') ? ['workspace' => $membership->workspace_id] : []) : null)
                    : $this->links->to($product, $tool['route']);

                return $href === null ? null : [...$tool, 'href' => $href, 'core' => $coreRoute];
            })->filter()->values();
        }

        return $moduleTools;
    }

    /** @return list<array{label: string, href: string, keywords: string}> */
    public function searchItems(PlatformUser $user, Workspace $workspace): array
    {
        $items = [];

        foreach ($this->forWorkspace($user, $workspace) as $product => $tools) {
            $productLabel = (string) config("platform.products.{$product}.label", ucfirst($product));

            foreach ($tools as $tool) {
                $items[] = [
                    'label' => $tool['label'],
                    'href' => $tool['href'],
                    'keywords' => $productLabel.' '.$tool['description'].' settings administration',
                ];
            }
        }

        return $items;
    }
}
