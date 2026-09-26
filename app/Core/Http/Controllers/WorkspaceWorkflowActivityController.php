<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\ProjectResourceDestinations;
use App\Core\Services\Projects\ProjectWorkflowProgress;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class WorkspaceWorkflowActivityController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        ProjectResourceDestinations $resourceDestinations,
        ProjectWorkflowProgress $workflowProgress,
        WorkspaceActivityProviderRegistry $activityProviders,
    ): View {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        $visibleProducts = WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('product')
            ->all();

        $connections = ProjectConnection::query()
            ->where('status', '!=', 'disconnected')
            ->whereNull('disconnected_at')
            ->whereHas('project', fn (Builder $query) => $query
                ->where('workspace_id', $workspace->getKey())
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereHas('memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->getKey())
                    ->where('status', 'active')
                    ->whereNull('revoked_at')))
            ->whereHas('sourceResource', fn (Builder $query) => $query
                ->whereIn('product', $visibleProducts)
                ->where('status', 'active')
                ->whereColumn('project_id', 'project_connections.project_id'))
            ->whereHas('targetResource', fn (Builder $query) => $query
                ->whereIn('product', $visibleProducts)
                ->where('status', 'active')
                ->whereColumn('project_id', 'project_connections.project_id'))
            ->with([
                'project.products' => fn ($query) => $query
                    ->whereIn('product', $visibleProducts)
                    ->where('status', 'active'),
                'sourceResource',
                'targetResource',
            ])
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get()
            ->filter(function (ProjectConnection $connection): bool {
                $activeProducts = $connection->project?->products->pluck('product')->all() ?? [];

                return in_array($connection->sourceResource?->product, $activeProducts, true)
                    && in_array($connection->targetResource?->product, $activeProducts, true);
            });

        $resources = $connections
            ->flatMap(fn (ProjectConnection $connection): array => array_filter([
                $connection->sourceResource,
                $connection->targetResource,
            ]))
            ->unique(fn ($resource): string => (string) $resource->getKey())
            ->values();
        $destinations = $resourceDestinations->forResources($user, $resources);
        $connections = $connections
            ->filter(fn (ProjectConnection $connection): bool => ($destinations[(string) $connection->source_resource_id]->state ?? null) === ProjectResourceDestinationState::Available
                && ($destinations[(string) $connection->target_resource_id]->state ?? null) === ProjectResourceDestinationState::Available)
            ->values();

        $workflowRuns = $workflowProgress->forConnections(
            $workspace,
            $connections,
            $access->canManageWorkspace($user, $workspace),
            limit: 30,
        );
        $accessibleProjects = $this->accessibleProjects($workspace, $user, $visibleProducts);
        $unavailableProducts = collect();

        foreach ($visibleProducts as $product) {
            $provider = $activityProviders->get($product);
            if ($provider === null) {
                continue;
            }

            $snapshot = $provider->recentForWorkspace($user, $workspace, $accessibleProjects, 30);

            if (! $snapshot->available) {
                $unavailableProducts->push((string) config('platform.products.'.$product.'.label', str($product)->headline()));
            }

            $workflowRuns = $workflowRuns->concat($snapshot->runs);
        }

        $workflowRuns = $workflowRuns
            ->sortByDesc(fn ($run): int => $run->recordedAt->getTimestamp())
            ->take(30)
            ->values();

        return view('core::workspaces.workflows', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'workflowRuns' => $workflowRuns,
            'unavailableProducts' => $unavailableProducts->unique()->values(),
            'contextProjects' => $this->contextProjects($workspace, $user),
        ]);
    }

    /** @return Collection<int, Project> */
    private function accessibleProjects(Workspace $workspace, PlatformUser $user, array $visibleProducts): Collection
    {
        if ($visibleProducts === []) {
            return collect();
        }

        return Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at'))
            ->whereHas('products', fn (Builder $query) => $query
                ->whereIn('product', $visibleProducts)
                ->where('status', 'active'))
            ->with(['products' => fn ($query) => $query
                ->whereIn('product', $visibleProducts)
                ->where('status', 'active')])
            ->get(['id', 'workspace_id', 'name', 'status', 'archived_at']);
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

    /** @return Collection<int, array{id: string, name: string, href: string}> */
    private function contextProjects(Workspace $workspace, PlatformUser $user): Collection
    {
        return Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at'))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'workspace_id', 'name'])
            ->map(fn (Project $project): array => [
                'id' => (string) $project->getKey(),
                'name' => $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]);
    }
}
