<?php

namespace App\Modules\Analytics\Services;

use App\Core\Services\WorkspaceProjectNavigation;
use App\Modules\Analytics\Models\Site;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceViewData
{
    public function __construct(private readonly Request $request) {}

    public function compose(View $view): void
    {
        $user = $this->request->user();
        $access = app(AnalyticsWorkspaceAccess::class);
        $workspaces = $user ? $access->workspacesFor($user) : collect();
        $siteParameter = $this->request->route('site') ?? $this->request->query('site');
        $currentSiteId = $siteParameter instanceof Site ? $siteParameter->getKey() : $siteParameter;
        $requestedSite = $siteParameter instanceof Site
            ? $siteParameter
            : (is_numeric($currentSiteId) ? Site::query()->find($currentSiteId) : null);
        $requestedSiteWorkspaceRecord = $requestedSite?->workspace;
        $requestedSiteWorkspace = $requestedSite !== null && $user !== null
            && $requestedSiteWorkspaceRecord !== null
            && $access->hasAccess($user, $requestedSiteWorkspaceRecord)
                ? $workspaces->firstWhere('id', (int) $requestedSite->workspace_id)
                : null;
        $currentWorkspace = $requestedSiteWorkspace
            ?? $workspaces->firstWhere('id', (int) $this->request->session()->get('analytics_workspace_id'))
            ?? $workspaces->first();

        if ($requestedSiteWorkspace !== null && $requestedSiteWorkspace->is($currentWorkspace)) {
            $currentSite = $requestedSite;
        } else {
            $currentSite = $currentWorkspace?->sites->firstWhere('id', (int) $currentSiteId)
                ?? $currentWorkspace?->sites->first();
        }

        $contextOptions = $currentWorkspace?->sites
            ->map(fn (Site $site): array => [
                'id' => $site->getKey(),
                'name' => $site->name,
                'href' => route('analytics.dashboard', ['site' => $site->getKey()]),
            ])
            ->all() ?? [];
        $currentWorkspaceRole = $user && $currentWorkspace
            ? $access->roleFor($user, $currentWorkspace)
            : null;
        $projectsUrl = $currentWorkspace
            ? app(WorkspaceProjectNavigation::class)->directoryUrl(
                'analytics',
                'workspace',
                $currentWorkspace->getKey(),
                route('analytics.dashboard'),
            )
            : route('analytics.dashboard');

        $groups = [[
            'label' => 'Reports',
            'items' => [[
                'label' => 'Overview',
                'route' => 'analytics.dashboard',
                'active' => $this->request->routeIs('analytics.dashboard'),
            ]],
        ]];

        $managementItems = [[
            'label' => 'Add website',
            'route' => 'analytics.sites.create',
            'icon' => 'plus',
            'active' => $this->request->routeIs('analytics.sites.create', 'analytics.sites.store'),
        ]];

        if ($currentSite) {
            $managementItems[] = [
                'label' => 'Goals',
                'href' => route('analytics.goals.index', $currentSite),
                'active' => $this->request->routeIs('analytics.goals.*'),
            ];
            $managementItems[] = [
                'label' => 'Site settings',
                'href' => route('analytics.sites.settings', $currentSite),
                'active' => $this->request->routeIs('analytics.sites.settings*'),
            ];
        }

        if ($currentWorkspace) {
            $managementItems[] = [
                'label' => 'Team access',
                'href' => route('analytics.workspaces.team', $currentWorkspace),
                'active' => $this->request->routeIs('analytics.workspaces.team'),
            ];
        }

        $groups[] = ['label' => 'Manage', 'items' => $managementItems];

        $view->with([
            'accountUser' => $user,
            'currentWorkspace' => $currentWorkspace,
            'workspaceOptions' => $workspaces,
            'currentAnalyticsSite' => $currentSite,
            'currentAnalyticsWorkspaceRole' => $currentWorkspaceRole,
            'signalTopbar' => [
                'projects_url' => $projectsUrl,
                'groups' => $groups,
                'workspaces' => $workspaces,
                'contexts' => $contextOptions,
                'profile' => [
                    ['label' => 'Profile and security', 'route' => 'analytics.account.profile', 'active' => $this->request->routeIs('analytics.account.profile')],
                ],
                'support' => [],
                'notifications_url' => null,
            ],
        ]);
    }
}
