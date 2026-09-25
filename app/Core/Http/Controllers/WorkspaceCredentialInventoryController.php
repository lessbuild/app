<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Credentials\WorkspaceCredential;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\PlatformProductRouteLinks;
use App\Core\Services\WorkspaceCredentialProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WorkspaceCredentialInventoryController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        WorkspaceCredentialProviderRegistry $credentialProviders,
        PlatformProductRouteLinks $productRoutes,
    ): View {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        $filters = $request->validate([
            'product' => ['nullable', Rule::in(['deployer', 'monitor', 'analytics'])],
            'status' => ['nullable', Rule::in(['active', 'expired', 'revoked'])],
            'type' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $visibleProducts = WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('product')
            ->unique()
            ->values()
            ->all();
        $projects = $this->accessibleProjects($workspace, $user, $visibleProducts);
        $credentials = collect();
        $unavailableProducts = collect();
        $productOptions = collect();

        foreach ($visibleProducts as $product) {
            $label = (string) config('platform.products.'.$product.'.label', str($product)->headline());
            $productOptions->put((string) $product, $label);
            $provider = $credentialProviders->get((string) $product);
            if ($provider === null) {
                continue;
            }

            $snapshot = $provider->credentialsForWorkspace($user, $workspace, $projects, 100);

            if (! $snapshot->available) {
                $unavailableProducts->push($label);
            }

            $credentials = $credentials->concat($snapshot->credentials);
        }

        $types = $credentials
            ->unique('type')
            ->sortBy('type')
            ->mapWithKeys(fn (WorkspaceCredential $credential): array => [$credential->type => $credential->type]);
        $credentials = $credentials
            ->filter(function (WorkspaceCredential $credential) use ($filters): bool {
                return (! isset($filters['product']) || $credential->product === $filters['product'])
                    && (! isset($filters['status']) || $credential->status === $filters['status'])
                    && (! isset($filters['type']) || $credential->type === $filters['type']);
            })
            ->sortByDesc(fn (WorkspaceCredential $credential): int => $credential->createdAt?->getTimestamp() ?? 0)
            ->values();
        $perPage = 25;
        $page = (int) ($filters['page'] ?? 1);
        $pageCredentials = new LengthAwarePaginator(
            $credentials->forPage($page, $perPage)->values(),
            $credentials->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('core::workspaces.credentials', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'contextProjects' => $this->contextProjects($workspace, $user),
            'credentials' => $pageCredentials,
            'unavailableProducts' => $unavailableProducts->unique()->values(),
            'productOptions' => $productOptions,
            'types' => $types,
            'selectedProduct' => $filters['product'] ?? '',
            'selectedStatus' => $filters['status'] ?? '',
            'selectedType' => $filters['type'] ?? '',
            'hasAnalyticsAccess' => in_array('analytics', $visibleProducts, true),
            'analyticsUrl' => in_array('analytics', $visibleProducts, true)
                ? $productRoutes->to('analytics', 'analytics.dashboard')
                : null,
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
