<?php

namespace App\Core\Services\Projects;

use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WorkspaceDashboardEnvironmentFilters
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    /**
     * Return environments backed by an active mapped product resource that this
     * member can reach through an active project and product grant.
     *
     * @return Collection<int, ProjectEnvironment>
     */
    public function availableFor(
        Workspace $workspace,
        PlatformUser $user,
        WorkspaceMembership $membership,
        ?string $includeEnvironmentId = null,
    ): Collection {
        $query = $this->queryFor($workspace, $user, $membership);
        $options = $query
            ->orderBy('project_id')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'project_id', 'name', 'environment_type', 'status']);

        if ($includeEnvironmentId !== null
            && ! $options->contains(fn (ProjectEnvironment $environment): bool => (string) $environment->getKey() === $includeEnvironmentId)) {
            $selectedEnvironment = $this->queryFor($workspace, $user, $membership)
                ->whereKey($includeEnvironmentId)
                ->first(['id', 'project_id', 'name', 'environment_type', 'status']);

            if ($selectedEnvironment !== null) {
                $options->prepend($selectedEnvironment);
            }
        }

        return $options;
    }

    public function isAvailableFor(
        Workspace $workspace,
        PlatformUser $user,
        WorkspaceMembership $membership,
        mixed $environmentId,
    ): bool {
        if (! is_string($environmentId) || ! Str::isUlid($environmentId)) {
            return false;
        }

        return $this->queryFor($workspace, $user, $membership)
            ->whereKey($environmentId)
            ->exists();
    }

    /** @return Builder<ProjectEnvironment> */
    private function queryFor(
        Workspace $workspace,
        PlatformUser $user,
        WorkspaceMembership $membership,
    ): Builder {
        $products = collect(ProductKey::values())
            ->filter(fn (string $product): bool => $this->access->hasProductAccess($membership, $product))
            ->values()
            ->all();

        return ProjectEnvironment::query()
            ->where('status', 'active')
            ->whereHas('project', function (Builder $project) use ($workspace, $user, $products): void {
                $project
                    ->where('workspace_id', $workspace->getKey())
                    ->where('status', 'active')
                    ->whereNull('archived_at')
                    ->whereHas('memberships', fn (Builder $memberships) => $memberships
                        ->where('user_id', $user->getKey())
                        ->where('status', 'active')
                        ->whereNull('revoked_at'))
                    ->whereExists(function ($query) use ($products): void {
                        $query->selectRaw('1')
                            ->from('project_resources')
                            ->join('project_products', function ($join): void {
                                $join->on('project_products.project_id', '=', 'project_resources.project_id')
                                    ->on('project_products.product', '=', 'project_resources.product');
                            })
                            ->whereColumn('project_resources.project_id', 'projects.id')
                            ->whereColumn('project_resources.environment_id', 'project_environments.id')
                            ->whereIn('project_resources.product', $products)
                            ->where('project_resources.status', 'active')
                            ->where('project_products.status', 'active');
                    });
            })
            ->with('project:id,name');
    }
}
