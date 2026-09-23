<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceDashboardSelection;
use App\Core\Models\WorkspaceDashboardView;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Models\WorkspaceProjectPin;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\ProjectProductSummaries;
use App\Core\Services\Projects\WorkspaceDashboardPriorities;
use App\Core\Services\ProjectSetup;
use App\Core\Services\Search\WorkspaceSearchPattern;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class WorkspaceDashboardController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        ProjectProductSummaries $productSummaries,
        ProjectSetup $projectSetup,
        WorkspaceDashboardPriorities $dashboardPriorities,
    ): View {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        $productGrants = WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy('product');
        $visibleProducts = $productGrants->keys()->all();
        $projectScope = fn (Builder $query) => $this->visibleProjects($query, $workspace, $user);
        $dashboardViews = WorkspaceDashboardView::query()
            ->where('workspace_id', $workspace->getKey())
            ->where(fn (Builder $query) => $query
                ->where('visibility', 'workspace')
                ->orWhere('owner_user_id', $user->getKey()))
            ->orderBy('name')
            ->get();
        $selection = WorkspaceDashboardSelection::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->first();
        $hasExplicitView = $request->query->has('view');
        $requestedView = $hasExplicitView ? $request->query('view') : $selection?->view_id;
        $selectedView = null;

        if (filled($requestedView) && $requestedView !== 'all') {
            $selectedView = $dashboardViews->firstWhere('id', (string) $requestedView);

            if ($hasExplicitView) {
                abort_if($selectedView === null, 404);
            }
        }

        if ($hasExplicitView || ($selection !== null && $requestedView !== null && $selectedView === null)) {
            WorkspaceDashboardSelection::query()->updateOrCreate([
                'workspace_id' => $workspace->getKey(),
                'user_id' => $user->getKey(),
            ], [
                'view_id' => $selectedView?->getKey(),
            ]);
        }

        $viewFilters = $selectedView?->filters ?? [];
        $viewProduct = $viewFilters['product'] ?? 'all';
        $validViewProduct = is_string($viewProduct) && in_array($viewProduct, ['all', 'deployer', 'monitor', 'analytics'], true);
        $viewProjectName = $viewFilters['project_name'] ?? '';
        $validViewProjectName = is_string($viewProjectName) && mb_strlen($viewProjectName) <= 80;
        $viewProjectName = $validViewProjectName ? trim($viewProjectName) : '';
        $selectedViewUnavailable = $selectedView !== null
            && (! $validViewProduct
                || ! $validViewProjectName
                || ($viewProduct !== 'all' && ! in_array($viewProduct, $visibleProducts, true)));
        $pinnedOnly = (bool) ($viewFilters['pinned_only'] ?? false);

        $visiblePins = WorkspaceProjectPin::query()
            ->where('workspace_id', $workspace->getKey())
            ->where(fn (Builder $query) => $query
                ->where('visibility', 'workspace')
                ->orWhere('owner_user_id', $user->getKey()));
        $viewScopePins = WorkspaceProjectPin::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('visibility', $selectedView?->visibility === 'workspace' ? 'workspace' : 'personal')
            ->when($selectedView?->visibility !== 'workspace', fn (Builder $query) => $query->where('owner_user_id', $user->getKey()));

        $projectCount = $this->visibleProjects(Project::query(), $workspace, $user)->count();
        $projectList = function () use ($workspace, $user, $visibleProducts, $viewProduct, $validViewProduct, $viewProjectName, $selectedViewUnavailable, $pinnedOnly, $viewScopePins): Builder {
            $query = $this->visibleProjects(Project::query(), $workspace, $user);

            if ($selectedViewUnavailable || ! $validViewProduct) {
                $query->whereRaw('1 = 0');
            } elseif ($viewProduct !== 'all') {
                $query->whereHas('products', fn (Builder $product) => $product
                    ->where('product', $viewProduct)
                    ->where('status', 'active'));
            }

            if ($viewProjectName !== '') {
                $query->whereRaw("projects.name LIKE ? ESCAPE '!'", [WorkspaceSearchPattern::contains($viewProjectName)]);
            }

            if ($pinnedOnly) {
                $query->whereIn('projects.id', (clone $viewScopePins)->select('project_id'));
            }

            return $query
                ->with([
                    'products' => fn ($products) => $visibleProducts === []
                        ? $products->whereRaw('1 = 0')
                        : $products->whereIn('product', $visibleProducts),
                ])
                ->withCount(['connections as active_connections_count' => function (Builder $connections) use ($visibleProducts): void {
                    $connections->where('status', '!=', 'disconnected')
                        ->whereNull('disconnected_at');

                    if ($visibleProducts === []) {
                        $connections->whereRaw('1 = 0');

                        return;
                    }

                    $connections->whereHas('sourceResource', fn (Builder $resource) => $resource
                        ->whereIn('product', $visibleProducts)
                        ->where('status', 'active')
                        ->whereColumn('project_id', 'project_connections.project_id'))
                        ->whereHas('targetResource', fn (Builder $resource) => $resource
                            ->whereIn('product', $visibleProducts)
                            ->where('status', 'active')
                            ->whereColumn('project_id', 'project_connections.project_id'));
                }]);
        };

        $pinnedProjects = $selectedViewUnavailable
            ? collect()
            : $projectList()
                ->whereIn('projects.id', (clone $visiblePins)->select('project_id'))
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get();
        $recentProjects = $selectedViewUnavailable
            ? collect()
            : $projectList()
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get();
        $projects = $pinnedProjects
            ->concat($recentProjects)
            ->unique(fn (Project $project): string => (string) $project->getKey())
            ->take(12)
            ->values();
        $projectPinStates = WorkspaceProjectPin::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('project_id', $projects->pluck('id'))
            ->where(fn (Builder $query) => $query
                ->where('visibility', 'workspace')
                ->orWhere('owner_user_id', $user->getKey()))
            ->get()
            ->groupBy('project_id')
            ->map(fn (Collection $pins): array => [
                'personal' => $pins->contains('visibility', 'personal'),
                'workspace' => $pins->contains('visibility', 'workspace'),
            ]);
        $projectSummaries = $projects->mapWithKeys(function (Project $project) use ($productSummaries, $user): array {
            $activeProducts = $project->products
                ->where('status', 'active')
                ->pluck('product')
                ->all();

            return [
                (string) $project->getKey() => $productSummaries->forProject($user, $project, $activeProducts),
            ];
        });
        $projectSetupSteps = $projects->mapWithKeys(function (Project $project) use ($projectSetup, $user): array {
            $activeProducts = $project->products
                ->where('status', 'active')
                ->pluck('product')
                ->all();

            return [
                (string) $project->getKey() => $projectSetup->forProject($user, $project, $activeProducts),
            ];
        });

        $activeProductCounts = $visibleProducts === []
            ? collect()
            : ProjectProduct::query()
                ->whereIn('product', $visibleProducts)
                ->where('status', 'active')
                ->whereHas('project', $projectScope)
                ->selectRaw('product, count(*) as total')
                ->groupBy('product')
                ->pluck('total', 'product');

        $connections = ProjectConnection::query()
            ->where('status', '!=', 'disconnected')
            ->whereNull('disconnected_at')
            ->whereHas('project', $projectScope)
            ->whereHas('sourceResource', fn (Builder $resource) => $resource
                ->whereIn('product', $visibleProducts)
                ->where('status', 'active')
                ->whereColumn('project_id', 'project_connections.project_id'))
            ->whereHas('targetResource', fn (Builder $resource) => $resource
                ->whereIn('product', $visibleProducts)
                ->where('status', 'active')
                ->whereColumn('project_id', 'project_connections.project_id'));

        if ($visibleProducts === []) {
            $connections->whereRaw('1 = 0');
        } else {
            $connections
                ->whereHas('sourceResource', fn (Builder $resource) => $resource->whereIn('product', $visibleProducts))
                ->whereHas('targetResource', fn (Builder $resource) => $resource->whereIn('product', $visibleProducts));
        }

        $recentConnections = (clone $connections)
            ->with(['project', 'sourceResource', 'targetResource'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
        $failedConnections = (clone $connections)
            ->where(fn (Builder $query) => $query->where('status', 'failed')->orWhereNotNull('last_error_code'))
            ->with(['project', 'sourceResource', 'targetResource'])
            ->orderByDesc('last_error_at')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();
        $priorities = $projects
            ->flatMap(fn (Project $project) => $dashboardPriorities->forProject(
                $workspace,
                $project,
                $projectSummaries->get((string) $project->getKey(), collect()),
                $projectSetupSteps->get((string) $project->getKey(), collect()),
                $failedConnections->where('project_id', $project->getKey()),
            ))
            ->sortBy('rank')
            ->take(8)
            ->values();

        $canManageBilling = $access->canManageBilling($user, $workspace);
        $subscriptions = $canManageBilling
            ? CurrentProductSubscription::query()
                ->where('workspace_id', $workspace->getKey())
                ->with('subscription')
                ->get()
                ->keyBy('product')
            : collect();

        return view('core::workspaces.dashboard', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'projects' => $projects,
            'dashboardViews' => $dashboardViews,
            'selectedView' => $selectedView,
            'selectedViewUnavailable' => $selectedViewUnavailable,
            'projectPinStates' => $projectPinStates,
            'projectSummaries' => $projectSummaries,
            'priorities' => $priorities,
            'projectCount' => $projectCount,
            'productGrants' => $productGrants,
            'activeProductCounts' => $activeProductCounts,
            'activeProductCount' => $activeProductCounts->sum(),
            'connectionCount' => $connections->count(),
            'recentConnections' => $recentConnections,
            'memberCount' => WorkspaceMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->currentlyActive()
                ->count(),
            'subscriptions' => $subscriptions,
            'canManageBilling' => $canManageBilling,
            'canCreateProjects' => $access->canManageWorkspace($user, $workspace),
            'canManageWorkspace' => $access->canManageWorkspace($user, $workspace),
            'contextProjects' => $this->contextProjects($workspace, $user),
        ]);
    }

    /** @return Builder<Project> */
    private function visibleProjects(Builder $query, Workspace $workspace, PlatformUser $user): Builder
    {
        return $query
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $memberships) => $memberships
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at'));
    }

    /** @return Collection<int, Workspace> */
    private function workspacesFor(PlatformUser $user): Collection
    {
        return Workspace::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $user->getKey())
                ->currentlyActive())
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, array{id:string,name:string,href:string}> */
    private function contextProjects(Workspace $workspace, PlatformUser $user): Collection
    {
        return $this->visibleProjects(Project::query(), $workspace, $user)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'workspace_id', 'name'])
            ->map(fn (Project $project): array => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]);
    }
}
