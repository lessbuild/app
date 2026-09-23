<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreWorkspaceProjectRequest;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\Projects\CreateCanonicalProject;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Contracts\View\View;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

        $projects = Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
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
            ->paginate(20);

        return view('core.projects.index', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'projects' => $projects,
            'productGrants' => $productGrants,
            'subscriptions' => $canManageBilling
                ? CurrentProductSubscription::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->with('subscription')
                    ->get()
                    ->keyBy('product')
                : collect(),
            'canCreateProjects' => $access->canManageWorkspace($user, $workspace),
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
        LegacyIdentityResolver $identities,
        WorkspaceProjectAccess $access,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_unless($project->workspace_id === $workspace->getKey(), 404);
        abort_unless($access->canViewProject($user, $project), 404);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);
        $canManageBilling = $access->canManageBilling($user, $workspace);

        $productGrants = WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy('product');
        $activeProjectProducts = ProjectProduct::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->pluck('product')
            ->all();
        $visibleProducts = array_values(array_intersect($productGrants->keys()->all(), $activeProjectProducts));
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
                : $query->whereHas('sourceResource', fn ($source) => $source->whereIn('product', $visibleProducts))
                    ->whereHas('targetResource', fn ($target) => $target->whereIn('product', $visibleProducts)),
            'connections.sourceResource',
            'connections.targetResource',
        ]);
        $legacyProjectId = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'project')
            ->where('canonical_entity', 'project')
            ->where('canonical_id', $project->getKey())
            ->where('status', 'reconciled')
            ->value('source_id');
        $productLinks = [];

        if ($legacyProjectId !== null && Route::has('projects.show')) {
            if (in_array('deployer', $visibleProducts, true)) {
                $deployerProjectUrl = $this->deployerProjectUrl($user, $identities, $legacyProjectId);

                if ($deployerProjectUrl !== null) {
                    $productLinks['deployer'] = $deployerProjectUrl;
                }
            }
        }

        foreach (['monitor', 'analytics'] as $product) {
            if (in_array($product, $visibleProducts, true)) {
                $productLinks[$product] = config('platform.products.'.$product.'.url');
            }
        }

        return view('core.projects.show', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'project' => $project,
            'contextProjects' => $this->contextProjects($workspace, $user),
            'productGrants' => $productGrants,
            'productLinks' => $productLinks,
            'subscriptions' => $canManageBilling
                ? CurrentProductSubscription::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->with('subscription')
                    ->get()
                    ->keyBy('product')
                : collect(),
            'canManageBilling' => $canManageBilling,
        ]);
    }

    public function selectWorkspace(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        abort_if($access->activeMembership($user, $workspace) === null, 404);

        return redirect()->route('core.projects.index', $workspace);
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $platformUser = $platformUsers->resolve($principal, 'deployer');
        abort_if($platformUser === null, 403);

        return $platformUser;
    }

    private function deployerProjectUrl(
        PlatformUser $platformUser,
        LegacyIdentityResolver $identities,
        string $legacyProjectId,
    ): ?string {
        try {
            $project = DeployerProject::query()->with('organization')->find($legacyProjectId);

            if ($project === null || $project->organization === null) {
                return null;
            }

            foreach ($identities->sourceIdsFor($platformUser, 'deployer') as $legacyUserId) {
                $deployerUser = DeployerUser::query()->find($legacyUserId);

                if ($deployerUser === null
                    || (int) $deployerUser->current_organization_id !== (int) $project->organization_id
                    || ! $project->organization->permits($deployerUser, 'view')) {
                    continue;
                }

                return route('projects.show', $legacyProjectId);
            }
        } catch (ConnectionException|QueryException) {
            return null;
        }

        return null;
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

    /** @return \Illuminate\Support\Collection<int, array{id:string,name:string,href:string}> */
    private function contextProjects(Workspace $workspace, PlatformUser $user): \Illuminate\Support\Collection
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
