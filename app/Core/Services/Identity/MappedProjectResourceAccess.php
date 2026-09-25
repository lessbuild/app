<?php

namespace App\Core\Services\Identity;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Adds canonical project restrictions to product-owned authorization.
 * Callers still own local role checks and native workspace query scoping.
 * Unmapped resources retain their native permissions; a stale map is not unmapped.
 */
final class MappedProjectResourceAccess
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly ResolvePlatformUser $users,
        private readonly WorkspaceProjectAccess $access,
    ) {}

    public function allows(
        Authenticatable $principal,
        string $product,
        string $resourceType,
        string|int $resourceId,
        string $sourceWorkspaceEntity,
        string|int $sourceWorkspaceId,
    ): bool {
        $denied = $this->deniedResourceIds($principal, $product, $resourceType, $sourceWorkspaceEntity, $sourceWorkspaceId, [(string) $resourceId]);

        return $denied !== null && ! in_array((string) $resourceId, $denied, true);
    }

    /**
     * Null denies the entire source query: the current Core context is invalid.
     * An empty list means no additional mapped-project exclusions (or legacy mode).
     * Provide candidate IDs when available to bound the Core lookup.
     *
     * @param  list<string|int>|null  $candidateIds
     * @return list<string>|null
     */
    public function deniedResourceIds(
        Authenticatable $principal,
        string $product,
        string $resourceType,
        string $sourceWorkspaceEntity,
        string|int $sourceWorkspaceId,
        ?array $candidateIds = null,
    ): ?array {
        if (! $this->authentication->usesCoreAuthority($product)) {
            return [];
        }

        $context = $this->context($principal, $product, $sourceWorkspaceEntity, $sourceWorkspaceId);
        if ($context === null) {
            return null;
        }
        [$user, $workspace] = $context;

        if ($candidateIds === []) {
            return [];
        }

        $resources = collect();
        $identities = collect();
        $chunks = $candidateIds === null ? [null] : array_chunk(array_values(array_unique(array_map('strval', $candidateIds))), 500);

        foreach ($chunks as $ids) {
            $resources = $resources->concat(ProjectResource::query()
                ->where('product', $product)
                ->where('resource_type', $resourceType)
                ->when($ids !== null, fn ($query) => $query->whereIn('resource_id', $ids))
                ->with(['project', 'environment'])
                ->get());
            $identities = $identities->concat(LegacyIdentityMap::query()
                ->where('source_product', $product)
                ->where('source_entity', $resourceType)
                ->when($ids !== null, fn ($query) => $query->whereIn('source_id', $ids))
                ->get());
        }

        $identityProjects = collect();
        foreach ($identities->where('canonical_entity', 'project')->pluck('canonical_id')->filter()->unique()->chunk(500) as $ids) {
            $identityProjects = $identityProjects->concat(Project::query()->whereKey($ids->all())->get());
        }
        $identityProjects = $identityProjects->keyBy(fn (Project $project): string => (string) $project->getKey());
        $identityEnvironments = collect();
        foreach ($identities->where('canonical_entity', 'project_environment')->pluck('canonical_id')->filter()->unique()->chunk(500) as $ids) {
            $identityEnvironments = $identityEnvironments->concat(ProjectEnvironment::query()->with('project')->whereKey($ids->all())->get());
        }
        $identityEnvironments = $identityEnvironments->keyBy(fn (ProjectEnvironment $environment): string => (string) $environment->getKey());

        $projectIds = $resources->pluck('project_id')->merge($identityProjects->keys())
            ->merge($identityEnvironments->pluck('project_id'))->filter()->unique();
        $allowedProjects = [];
        $permitted = $this->access->accessibleProductProjects($user, $workspace, $product);
        foreach ($projectIds->chunk(500) as $ids) {
            foreach ((clone $permitted)->whereKey($ids->all())->pluck('id') as $id) {
                $allowedProjects[(string) $id] = true;
            }
        }

        $resources = $resources->groupBy(fn (ProjectResource $resource): string => (string) $resource->resource_id);
        $identities = $identities->groupBy(fn (LegacyIdentityMap $identity): string => (string) $identity->source_id);
        $mappedIds = $resources->keys()->merge($identities->keys())->unique();
        $denied = [];

        foreach ($mappedIds as $id) {
            if (! $this->allowsMappings($workspace, $product, $resources->get($id, collect()), $identities->get($id, collect()), $identityProjects, $identityEnvironments, $allowedProjects)) {
                $denied[] = (string) $id;
            }
        }

        return $denied;
    }

    /** @return array{PlatformUser, Workspace}|null */
    private function context(Authenticatable $principal, string $product, string $sourceWorkspaceEntity, string|int $sourceWorkspaceId): ?array
    {
        $resolved = $this->users->resolve($principal, $product);
        $user = $resolved === null ? null : PlatformUser::query()->whereKey($resolved->getKey())->where('status', 'active')->first();
        if ($user === null) {
            return null;
        }

        $maps = LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', $sourceWorkspaceEntity)
            ->where('source_id', (string) $sourceWorkspaceId)
            ->get();
        if ($maps->count() !== 1 || $maps->first()->status !== 'reconciled' || $maps->first()->canonical_entity !== 'workspace') {
            return null;
        }

        $workspace = Workspace::query()->find($maps->first()->canonical_id);
        $membership = $workspace === null ? null : $this->access->activeMembership($user, $workspace);
        if ($membership === null || ! $this->access->hasProductAccess($membership, $product)) {
            return null;
        }

        return [$user, $workspace];
    }

    /** @param array<string, true> $allowedProjects */
    private function allowsMappings(Workspace $workspace, string $product, Collection $resources, Collection $identities, Collection $identityProjects, Collection $identityEnvironments, array $allowedProjects): bool
    {
        if ($resources->count() > 1 || $identities->count() > 1) {
            return false;
        }

        $project = null;
        $environment = null;
        $resource = $resources->first();
        if ($resource !== null) {
            if (! $this->resourceIsAccessible($product, $resource->resource_type, $resource->status) || $resource->project === null) {
                return false;
            }
            $project = $resource->project;
            $environment = $resource->environment;
            if ($resource->resource_type === 'environment' && $resource->environment_id === null) {
                return false;
            }
            if ($resource->environment_id !== null && ($environment === null || ! $this->resourceIsAccessible($product, 'environment', $environment->status) || (string) $environment->project_id !== (string) $project->getKey())) {
                return false;
            }
        }

        $identity = $identities->first();
        if ($identity !== null) {
            if ($identity->status !== 'reconciled' || ! filled($identity->canonical_id)) {
                return false;
            }
            if ($identity->source_entity === 'environment' && $identity->canonical_entity !== 'project_environment') {
                return false;
            }

            if ($identity->canonical_entity === 'project') {
                $identityProject = $identityProjects->get((string) $identity->canonical_id);
            } elseif ($identity->canonical_entity === 'project_environment') {
                $identityEnvironment = $identityEnvironments->get((string) $identity->canonical_id);
                if ($identityEnvironment === null || ! $this->resourceIsAccessible($product, 'environment', $identityEnvironment->status)
                    || ($environment !== null && (string) $environment->getKey() !== (string) $identityEnvironment->getKey())) {
                    return false;
                }
                $identityProject = $identityEnvironment->project;
            } else {
                return false;
            }

            if ($identityProject === null || ($project !== null && (string) $identityProject->getKey() !== (string) $project->getKey())) {
                return false;
            }
            $project ??= $identityProject;
        }

        if ($project === null || (string) $project->workspace_id !== (string) $workspace->getKey()) {
            return false;
        }

        $projectId = (string) $project->getKey();

        return isset($allowedProjects[$projectId]);
    }

    private function resourceIsAccessible(string $product, string $type, string $status): bool
    {
        // Importers preserve collection pauses as resource/environment state.
        // They do not revoke a member's permission to inspect or resume collection.
        return $status === 'active' || ($status === 'paused' && (
            ($product === 'analytics' && $type === 'site')
            || ($product === 'monitor' && $type === 'environment')
        ));
    }
}
