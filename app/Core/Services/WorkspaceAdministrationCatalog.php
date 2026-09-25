<?php

namespace App\Core\Services;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use Illuminate\Support\Collection;

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
            ['label' => 'Costs, estimates, and budgets', 'description' => 'Review infrastructure cost estimates, usage, and budget settings.', 'route' => 'costs.index'],
            ['label' => 'Automation, schedules, and API', 'description' => 'Manage workflows, scheduled deployments/tasks, scaling, and automation tokens.', 'route' => 'automation.index'],
            ['label' => 'Commands, recipes, and templates', 'description' => 'Review operational commands, reusable recipes, and deployment templates.', 'route' => 'commands.index'],
            ['label' => 'Deployment recipes', 'description' => 'Create and manage reusable deployment recipes.', 'route' => 'recipes.index'],
            ['label' => 'Template gallery', 'description' => 'Browse, compare, and manage reusable configuration recipes.', 'route' => 'gallery.index'],
            ['label' => 'Activity history', 'description' => 'Review recent builds, infrastructure changes, and workspace activity.', 'route' => 'activity.index'],
            ['label' => 'Notification center', 'description' => 'Review and configure Deployer notifications.', 'route' => 'notifications.index'],
            ['label' => 'System health', 'description' => 'Deployer runtime and infrastructure diagnostics.', 'route' => 'system-health.index'],
            ['label' => 'Observability', 'description' => 'Operational investigations, incidents, and service status.', 'route' => 'observability.index'],
            ['label' => 'GitHub App setup', 'description' => 'Repository integration and application credentials.', 'route' => 'admin.github-app.setup'],
            ['label' => 'Deployer billing', 'description' => 'Open the existing Deployer plan, invoices, and payment settings.', 'route' => 'billing.index'],
            ['label' => 'Deployer account security', 'description' => 'Review Deployer account profile and sign-in security settings.', 'route' => 'account.index'],
            ['label' => 'Feedback review', 'description' => 'Review existing Deployer feedback while its records remain in the Deployer database.', 'route' => 'feedback.index'],
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
            ['label' => 'Status pages', 'description' => 'Create and publish customer-facing Monitor status pages.', 'route' => 'monitor.status-pages.index'],
            ['label' => 'Alert destinations', 'description' => 'Manage notification targets and delivery history.', 'route' => 'monitor.alert-destinations.index'],
            ['label' => 'Alert rules', 'description' => 'Tune alert conditions and escalation routing.', 'route' => 'monitor.alerts.index'],
            ['label' => 'Monitors and checks', 'description' => 'Configure uptime and scheduled health checks.', 'route' => 'monitor.monitors.index'],
            ['label' => 'Service objectives', 'description' => 'Set service-level objectives and review reliability targets.', 'route' => 'monitor.objectives.index'],
            ['label' => 'Maintenance windows', 'description' => 'Schedule planned maintenance and alert suppression.', 'route' => 'monitor.maintenance-windows.index'],
            ['label' => 'Integrations', 'description' => 'Connect telemetry and external services.', 'route' => 'monitor.settings.integrations'],
            ['label' => 'Data and privacy', 'description' => 'Review Monitor data controls and exports.', 'route' => 'monitor.settings.data'],
            ['label' => 'Notifications', 'description' => 'Configure workspace notification preferences.', 'route' => 'monitor.settings.notifications'],
            ['label' => 'Audit log', 'description' => 'Review Monitor workspace changes.', 'route' => 'monitor.settings.audit-log'],
            ['label' => 'Team settings', 'description' => 'Manage the Monitor workspace team.', 'route' => 'monitor.settings.team'],
            ['label' => 'Monitor billing', 'description' => 'Open Monitor plan, usage, and payment settings.', 'route' => 'monitor.settings.billing'],
        ],
        'analytics' => [
            ['label' => 'Sites and reporting', 'description' => 'Choose an Analytics site, review traffic reports, and manage report filters.', 'route' => 'analytics.dashboard'],
            ['label' => 'Site setup and tracking', 'description' => 'Add a site, verify its domain, and install the event collector.', 'route' => 'analytics.sites.create'],
            ['label' => 'Goals and conversions', 'description' => 'Open Analytics to manage path/event goals and conversion reporting.', 'route' => 'analytics.dashboard'],
            ['label' => 'Site settings and data controls', 'description' => 'Open Analytics to manage privacy, collection, retention, and export settings by site.', 'route' => 'analytics.dashboard'],
            ['label' => 'Exports and processing history', 'description' => 'Review site-scoped report exports and event processing in Analytics.', 'route' => 'analytics.dashboard'],
            ['label' => 'Analytics workspaces and team', 'description' => 'Review Analytics workspace memberships and invitations.', 'route' => 'analytics.workspaces.index'],
            ['label' => 'Account settings and security', 'description' => 'Manage Analytics profile and account preferences.', 'route' => 'analytics.account.profile'],
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
            if (! $this->access->hasProductAccess($membership, $product)) {
                continue;
            }

            $moduleTools[$product] = collect($tools)->map(function (array $tool) use ($product): ?array {
                $href = $this->links->to($product, $tool['route']);

                return $href === null ? null : [...$tool, 'href' => $href];
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
