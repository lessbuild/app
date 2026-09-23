<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Http\Requests\StoreProjectResourceRequest;
use App\Core\Http\Requests\StoreWorkspaceProjectRequest;
use App\Core\Http\Requests\UpdateWorkspaceProjectRequest;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Connections\ProjectConnectionDiagnostics;
use App\Core\Services\Connections\ProjectConnectionEntitlementPolicy;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\ProjectProductLinks;
use App\Core\Services\ProjectProductSummaries;
use App\Core\Services\ProjectResourceDestinations;
use App\Core\Services\ProjectResourceLinks;
use App\Core\Services\Projects\CreateCanonicalProject;
use App\Core\Services\Projects\SetCanonicalProjectArchiveState;
use App\Core\Services\Projects\UpdateCanonicalProject;
use App\Core\Services\ProjectSetup;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class WorkspaceProjectsController
{
    public function index(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        $productGrants = WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy('product');
        $visibleProducts = $productGrants->keys()->all();
        $canManageBilling = $access->canManageBilling($user, $workspace);
        $canManageProjects = $access->canManageWorkspace($user, $workspace);
        $projectStatus = $request->query('status') === 'archived' ? 'archived' : 'active';

        $projects = Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', $projectStatus)
            ->when($projectStatus === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($projectStatus === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at'))
            ->with([
                'products' => fn ($query) => $visibleProducts === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('product', $visibleProducts),
            ])
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->appends($request->query());

        return view('core.projects.index', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'projects' => $projects,
            'projectStatus' => $projectStatus,
            'productGrants' => $productGrants,
            'subscriptions' => $canManageBilling
                ? CurrentProductSubscription::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->with('subscription')
                    ->get()
                    ->keyBy('product')
                : collect(),
            'canCreateProjects' => $canManageProjects,
            'canManageProjects' => $canManageProjects,
            'canManageBilling' => $canManageBilling,
            'contextProjects' => $this->contextProjects($workspace, $user),
        ]);
    }

    public function create(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($access->canManageWorkspace($user, $workspace), 403);

        return view('core.projects.create', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'contextProjects' => $this->contextProjects($workspace, $user),
        ]);
    }

    public function edit(
        Request $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        abort_unless($project->status === 'active' && $project->archived_at === null, 404);
        abort_unless($access->canManageWorkspace($user, $workspace), 403);

        return view('core.projects.edit', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'project' => $project,
            'contextProjects' => $this->contextProjects($workspace, $user),
        ]);
    }

    public function update(
        UpdateWorkspaceProjectRequest $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        UpdateCanonicalProject $updateProject,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);

        $user = $this->platformUser($request, $platformUsers);
        $updateProject->handle(
            user: $user,
            workspace: $workspace,
            project: $project,
            name: $request->validated('name'),
            description: $request->validated('description'),
        );

        return redirect()
            ->route('core.projects.show', [$workspace, $project])
            ->with('success', __('Project details updated.'));
    }

    public function archive(
        Request $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        SetCanonicalProjectArchiveState $archiveProject,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);

        $archiveProject->handle(
            $this->platformUser($request, $platformUsers),
            $workspace,
            $project,
            archived: true,
        );

        return redirect()
            ->route('core.projects.index', ['workspace' => $workspace, 'status' => 'archived'])
            ->with('success', __('Project archived. Its resources, subscriptions, and connection history were preserved.'));
    }

    public function restore(
        Request $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        SetCanonicalProjectArchiveState $archiveProject,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);

        $archiveProject->handle(
            $this->platformUser($request, $platformUsers),
            $workspace,
            $project,
            archived: false,
        );

        return redirect()
            ->route('core.projects.show', [$workspace, $project])
            ->with('success', __('Project restored. Its existing app links and history are available again.'));
    }

    public function store(
        StoreWorkspaceProjectRequest $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        CreateCanonicalProject $createProject,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        $project = $createProject->handle(
            workspace: $workspace,
            creator: $user,
            name: $request->validated('name'),
            description: $request->validated('description'),
        );

        return redirect()
            ->route('core.projects.show', [$workspace, $project])
            ->with('success', __('Project created. Connect Deployer, Monitor, or Analytics when you are ready.'));
    }

    public function show(
        Request $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        ProjectProductLinks $productLinks,
        WorkspaceProjectAccess $access,
        ProjectConnectionEntitlementPolicy $connectionEntitlements,
        ProjectProductSummaries $productSummaries,
        ProjectSetup $projectSetup,
        ProjectResourceLinks $resourceLinks,
        ProjectResourceDestinations $resourceDestinations,
        ProjectConnectionDiagnostics $connectionDiagnostics,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        abort_unless($access->canViewProject($user, $project), 404);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);
        $canManageBilling = $access->canManageBilling($user, $workspace);
        $canManageProjects = $access->canManageWorkspace($user, $workspace);
        $canManageConnections = $canManageProjects;

        $productGrants = WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy('product');
        $availableProducts = $productGrants->keys()->all();
        $activeProjectProducts = ProjectProduct::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->pluck('product')
            ->all();
        $visibleProducts = array_values(array_intersect($productGrants->keys()->all(), $activeProjectProducts));
        $projectSetupSteps = $projectSetup->forProject($user, $project, $availableProducts);
        $project->load([
            'products' => fn ($query) => $visibleProducts === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('product', $visibleProducts),
            'environments' => fn ($query) => $visibleProducts === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('resources', fn ($resources) => $resources->whereIn('product', $visibleProducts)),
            'environments.resources' => fn ($query) => $visibleProducts === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('product', $visibleProducts),
            'resources' => fn ($query) => $visibleProducts === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('product', $visibleProducts),
            'resources.environment',
            'connections' => fn ($query) => $visibleProducts === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('sourceResource', fn ($source) => $source
                    ->whereIn('product', $visibleProducts)
                    ->where('project_id', $project->getKey())
                    ->where('status', 'active'))
                    ->whereHas('targetResource', fn ($target) => $target
                        ->whereIn('product', $visibleProducts)
                        ->where('project_id', $project->getKey())
                        ->where('status', 'active'))
                    ->where('status', '!=', 'disconnected')
                    ->whereNull('disconnected_at'),
            'connections.sourceResource',
            'connections.sourceResource.environment',
            'connections.targetResource',
            'connections.targetResource.environment',
            'connections.events.actor',
            'connections.deliveries',
        ]);
        $authorizedProductLinks = $productLinks->forProject($user, $project, $visibleProducts);
        $resourceDestinationsForProject = $resourceDestinations->forResources($user, $project->resources);
        $projectConnections = $project->connections
            ->filter(fn ($connection): bool => ($resourceDestinationsForProject[(string) $connection->source_resource_id]->state ?? null) === ProjectResourceDestinationState::Available
                && ($resourceDestinationsForProject[(string) $connection->target_resource_id]->state ?? null) === ProjectResourceDestinationState::Available)
            ->values();
        $hiddenConnectionCount = $project->connections->count() - $projectConnections->count();
        $connectionResources = $project->resources
            ->filter(fn ($resource): bool => $resource->status === 'active'
                && ($resourceDestinationsForProject[(string) $resource->getKey()]->state ?? null) === ProjectResourceDestinationState::Available)
            ->sortBy([
                ['product', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        return view('core.projects.show', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'project' => $project,
            'contextProjects' => $this->contextProjects($workspace, $user),
            'productGrants' => $productGrants,
            'productLinks' => $authorizedProductLinks,
            'productSummaries' => $productSummaries->forProject($user, $project, $visibleProducts),
            'projectSetupSteps' => $projectSetupSteps,
            'resourceDestinations' => $resourceDestinationsForProject,
            'connectionDiagnostics' => $connectionDiagnostics->forConnections($projectConnections),
            'projectConnections' => $projectConnections,
            'hiddenConnectionCount' => $hiddenConnectionCount,
            'resourceCandidates' => $canManageConnections
                ? $resourceLinks->candidates($user, $availableProducts)
                : collect(),
            'subscriptions' => $canManageBilling
                ? CurrentProductSubscription::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->with('subscription')
                    ->get()
                    ->keyBy('product')
                : collect(),
            'canManageBilling' => $canManageBilling,
            'canManageProjects' => $canManageProjects,
            'canManageConnections' => $canManageConnections,
            'connectionCapabilities' => $connectionEntitlements->availableFor($project),
            'connectionResources' => $connectionResources,
        ]);
    }

    public function storeResource(
        StoreProjectResourceRequest $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        ProjectResourceLinks $resourceLinks,
    ): RedirectResponse {
        abort_unless($project->workspace_id === $workspace->getKey(), 404);

        $user = $this->platformUser($request, $platformUsers);
        abort_unless($access->canManageWorkspace($user, $workspace), 403);

        $product = $request->validated('product');
        abort_unless($access->canLinkProductResource($user, $project, $product), 404);

        $resource = $resourceLinks->link($user, $project, $product, $request->validated('resource_id'));
        abort_if($resource === null, 404);

        return redirect()
            ->route('core.projects.show', [$workspace, $project]).'#resources'
            ->with('success', __('Existing :product resource linked to this project.', [
                'product' => str($product)->headline(),
            ]));
    }

    public function selectWorkspace(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        abort_if($access->activeMembership($user, $workspace) === null, 404);

        return redirect()->route('core.workspace.dashboard', $workspace);
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $platformUser = $platformUsers->resolve($principal, 'deployer');
        abort_if($platformUser === null, 403);

        return $platformUser;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Workspace> */
    private function workspacesFor(PlatformUser $user): \Illuminate\Database\Eloquent\Collection
    {
        return Workspace::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active'))
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, array{id:string,name:string,href:string}> */
    private function contextProjects(Workspace $workspace, PlatformUser $user): Collection
    {
        $projects = Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at'))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'workspace_id', 'name']);

        return $projects->map(fn (Project $project): array => [
            'id' => $project->getKey(),
            'name' => $project->name,
            'href' => route('core.projects.show', [$workspace, $project]),
        ]);
    }
}
