<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Projects\WorkspaceWebhookDelivery;
use App\Core\Http\Middleware\EnsureWorkspaceFeatureRollout;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\WorkspaceProjectAccess;
use App\Core\Services\WorkspaceWebhookDeliveryProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WorkspaceWebhookDeliveryHistoryController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        WorkspaceWebhookDeliveryProviderRegistry $deliveryProviders,
    ): View {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        $filters = $request->validate([
            'product' => ['nullable', Rule::in(['deployer', 'monitor', 'analytics'])],
            'status' => ['nullable', 'string', 'max:32'],
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
        $deliveries = collect();
        $unavailableProducts = collect();
        $productOptions = collect();

        foreach ($visibleProducts as $product) {
            $provider = $deliveryProviders->get((string) $product);
            if ($provider === null) {
                continue;
            }

            $label = (string) config('platform.products.'.$product.'.label', str($product)->headline());
            $productOptions->put((string) $product, $label);
            $snapshot = $provider->recentWebhookDeliveriesForWorkspace($user, $workspace, $projects, 100);

            if (! $snapshot->available) {
                $unavailableProducts->push($label);
            }

            $deliveries = $deliveries->concat($snapshot->deliveries);
        }

        $statuses = $deliveries
            ->unique('status')
            ->sortBy('statusLabel')
            ->mapWithKeys(fn (WorkspaceWebhookDelivery $delivery): array => [$delivery->status => $delivery->statusLabel]);
        $deliveries = $deliveries
            ->filter(function (WorkspaceWebhookDelivery $delivery) use ($filters): bool {
                return (! isset($filters['product']) || $delivery->product === $filters['product'])
                    && (! isset($filters['status']) || $delivery->status === $filters['status']);
            })
            ->sortByDesc(fn (WorkspaceWebhookDelivery $delivery): int => $delivery->recordedAt->getTimestamp())
            ->values();
        $perPage = 25;
        $page = (int) ($filters['page'] ?? 1);
        $pageDeliveries = new LengthAwarePaginator(
            $deliveries->forPage($page, $perPage)->values(),
            $deliveries->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $request->attributes->set(EnsureWorkspaceFeatureRollout::DEGRADED_ATTRIBUTE, $unavailableProducts->isNotEmpty());

        return view('core::workspaces.deliveries', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'contextProjects' => $this->contextProjects($workspace, $user),
            'deliveries' => $pageDeliveries,
            'unavailableProducts' => $unavailableProducts->unique()->values(),
            'productOptions' => $productOptions,
            'statuses' => $statuses,
            'selectedProduct' => $filters['product'] ?? '',
            'selectedStatus' => $filters['status'] ?? '',
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
