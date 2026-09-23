<?php

namespace App\Modules\Monitor\Services;

use App\Core\Services\WorkspaceProjectNavigation;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceViewData
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly Request $request,
        private readonly WorkspaceUsage $usage,
        private readonly WorkspaceNavigation $navigation,
        private readonly MonitorPlanAuthority $planAuthority,
    ) {}

    public function compose(View $view): void
    {
        $workspace = $this->currentWorkspace->get();
        $plan = config('monitor.beacon.plans.'.$workspace->plan, config('monitor.beacon.plans.free'));

        if ($this->planAuthority->usesCore()) {
            $resolvedPlan = $this->planAuthority->resolve($workspace);
            $plan = $resolvedPlan->available
                ? array_replace($plan, $resolvedPlan->snapshot)
                : ['name' => 'Plan unverified', 'description' => 'Monitor could not confirm this workspace’s Core plan.'];
        }

        $usageSummary = $this->usage->summary($workspace);
        $application = $this->request->route('application');
        $environment = $this->request->route('environment');

        if (! $application instanceof Application && $environment instanceof Environment) {
            $application = $environment->application;
        }

        $applications = $workspace->applications()
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name']);

        $contextOptions = $applications->map(fn (Application $option): array => [
            'id' => $option->getKey(),
            'name' => $option->name,
            'href' => route('monitor.applications.show', $option),
        ])->all();

        $environmentOptions = $application instanceof Application
            ? $application->environments()
                ->orderBy('name')
                ->get(['id', 'application_id', 'name'])
                ->map(fn (Environment $option): array => [
                    'id' => $option->getKey(),
                    'name' => $option->name,
                    'href' => route('monitor.environments.show', [$application, $option]),
                ])->all()
            : [];

        $productNavigation = $this->navigation->forWorkspace($workspace);
        $projectsUrl = app(WorkspaceProjectNavigation::class)->directoryUrl(
            'monitor',
            'workspace',
            $workspace->getKey(),
            route('monitor.applications.index'),
        );
        $groups = collect($productNavigation)->map(fn (array $items, string $label): array => [
            'label' => $label,
            'items' => array_values($items),
        ])->values()->all();

        $view->with([
            'currentWorkspace' => $workspace,
            'workspaceOptions' => $this->request->user()->workspaces()->orderBy('name')->get(),
            'accountUser' => $this->request->user(),
            'workspacePlan' => $plan,
            'workspaceUsageSummary' => $usageSummary,
            'workspaceEventCount' => $usageSummary['event_count'],
            'workspaceUsagePercentage' => $usageSummary['percentage'],
            'workspaceOpenIssues' => Issue::forWorkspace($workspace)->where('status', 'open')->count(),
            'workspaceNavigation' => $productNavigation,
            'signalTopbar' => [
                'projects_url' => $projectsUrl,
                'groups' => $groups,
                'profile' => [
                    ['label' => 'Team access', 'route' => 'monitor.settings.team', 'href' => route('monitor.settings.team'), 'active' => $this->request->routeIs('monitor.settings.team')],
                    ['label' => 'Plans & billing', 'route' => 'monitor.settings.billing', 'href' => route('monitor.settings.billing'), 'active' => $this->request->routeIs('monitor.settings.billing')],
                ],
                'support' => [
                    ['label' => 'Integrations', 'route' => 'monitor.settings.integrations', 'href' => route('monitor.settings.integrations'), 'active' => $this->request->routeIs('monitor.settings.integrations')],
                    ['label' => 'API reference', 'route' => 'monitor.settings.api', 'href' => route('monitor.settings.api'), 'active' => $this->request->routeIs('monitor.settings.api')],
                ],
                'workspaces' => $this->request->user()->workspaces()->orderBy('name')->get(),
                'contexts' => $contextOptions,
                'notifications_url' => null,
            ],
            'signalTopbarContext' => $application,
            'signalTopbarEnvironments' => $environmentOptions,
            'signalTopbarEnvironment' => $environment,
        ]);
    }
}
