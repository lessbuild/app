<?php

namespace App\Core\Services\Restoration;

use App\Core\Data\Restoration\NativeRestorationState;
use App\Core\Data\Restoration\ResourceRestorationAttempt;
use App\Core\Data\Restoration\ResourceRestorationTarget;
use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\ResourceRestorationRequest;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceProjectAccess;

/** Core authorization never takes source locks. Providers separately enforce native management roles. */
final class ResourceRestorationAuthority
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function target(PlatformUser $actor, ProjectResource $resource): ResourceRestorationTarget
    {
        $resource = ProjectResource::query()->find($resource->getKey());
        $this->require($resource !== null, 'resource_mapping_changed');
        $project = Project::query()->find($resource->project_id);
        $this->require($project !== null, 'project_unavailable');
        $maps = LegacyIdentityMap::query()->where('source_product', $resource->product)
            ->where('source_entity', 'workspace')->where('canonical_entity', 'workspace')
            ->where('canonical_id', $project->workspace_id)->get();
        $this->require($maps->count() === 1 && $maps->first()->status === 'reconciled', 'workspace_mapping_changed');
        $sourceWorkspaceId = (string) $maps->first()->source_id;
        foreach ([$resource->metadata['source_workspace_id'] ?? null, $project->metadata['source_workspace_id'] ?? null] as $declaredId) {
            $this->require($declaredId === null || (string) $declaredId === $sourceWorkspaceId, 'workspace_mapping_changed');
        }
        $target = new ResourceRestorationTarget(
            $resource->product, $resource->resource_type, (string) $resource->resource_id,
            (string) $resource->getKey(), (string) $project->workspace_id, (string) $project->getKey(),
            $resource->environment_id, 'workspace', $sourceWorkspaceId,
        );
        $this->authorize($actor, $target);

        return $target;
    }

    public function authorize(PlatformUser $actor, ResourceRestorationTarget $target, bool $lock = false, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Restoration): void
    {
        $user = PlatformUser::query()->whereKey($actor->getKey())->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $this->require($user?->status === 'active', 'actor_unavailable');
        $workspace = Workspace::query()->whereKey($target->workspaceId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $this->require($workspace !== null, 'workspace_unavailable');
        $this->require(config('platform.products.'.$target->product.'.enabled', false), 'product_unavailable');
        // Lock the same authority rows read by the common access query during projection.
        if ($lock) {
            $workspace->memberships()->where('user_id', $user->getKey())->lockForUpdate()->get();
            WorkspaceProductAccess::query()->whereIn('membership_id', $workspace->memberships()->where('user_id', $user->getKey())->select('id'))->where('product', $target->product)->lockForUpdate()->get();
            ProjectMembership::query()->where('project_id', $target->projectId)->where('user_id', $user->getKey())->lockForUpdate()->get();
            ProjectProduct::query()->where('project_id', $target->projectId)->where('product', $target->product)->lockForUpdate()->get();
        }
        $project = $this->access->accessibleProductProjects($user, $workspace, $target->product, $purpose)
            ->whereKey($target->projectId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $this->require($project !== null, 'restoration_authority_changed');
        if ($purpose === ProjectResourceAccessPurpose::Restoration) {
            $membership = $this->access->activeMembership($user, $workspace);
            $grant = $membership === null ? null : WorkspaceProductAccess::query()
                ->where('membership_id', $membership->getKey())->where('product', $target->product)
                ->where('status', 'active')->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
            $this->require($grant !== null && in_array($grant->role, ['owner', 'admin'], true), 'restoration_role_changed');
        }
        $maps = LegacyIdentityMap::query()->where('source_product', $target->product)
            ->where('source_entity', $target->sourceWorkspaceEntity)->where('source_id', $target->sourceWorkspaceId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $this->require($maps->count() === 1 && $maps->first()->status === 'reconciled'
            && $maps->first()->canonical_entity === 'workspace'
            && (string) $maps->first()->canonical_id === $target->workspaceId, 'workspace_mapping_changed');
    }

    /**
     * Bind identities, lifecycle states and provenance. A completely unmapped native child can
     * remain native; an identity-only, orphaned or contradictory child always blocks.
     *
     * @param  list<NativeRestorationState>  $states
     */
    public function bindings(ResourceRestorationTarget $target, array $states, bool $lock = false, ?string $actorId = null): array
    {
        $bindings = [];
        $seen = [];
        foreach ($states as $state) {
            $key = $state->resourceType.':'.$state->resourceId;
            $this->require(! isset($seen[$key]) && in_array($state->status, ['active', 'paused', 'archived'], true), 'invalid_source_snapshot');
            $this->require(($state->resourceType === $target->resourceType && $state->resourceId === $target->resourceId)
                || ($target->resourceType === 'application' && $state->resourceType === 'environment'), 'invalid_source_snapshot');
            $seen[$key] = true;
            $resources = ProjectResource::query()->where('product', $target->product)->where('resource_type', $state->resourceType)
                ->where('resource_id', $state->resourceId)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $maps = LegacyIdentityMap::query()->where('source_product', $target->product)->where('source_entity', $state->resourceType)
                ->where('source_id', $state->resourceId)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $isTarget = $state->resourceType === $target->resourceType && $state->resourceId === $target->resourceId;
            if (! $isTarget && $resources->isEmpty() && $maps->isEmpty()) {
                $bindings[$key] = null;

                continue;
            }
            $this->require($resources->count() === 1 && $maps->count() <= 1, 'resource_mapping_changed');
            $resource = $resources->first();
            $map = $maps->first();
            $this->require($resource->project_id === $target->projectId
                && in_array($resource->status, ['active', 'paused', 'archived'], true)
                && (! $isTarget || (string) $resource->getKey() === $target->projectResourceId)
                && ($map === null || ($map->status === 'reconciled'
                && (! isset($map->metadata['project_resource_id']) || (string) $map->metadata['project_resource_id'] === (string) $resource->getKey()))), 'resource_mapping_changed');
            $environment = null;
            $environmentResources = [];
            if ($state->resourceType === 'environment') {
                $environment = ProjectEnvironment::query()->whereKey($resource->environment_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
                $this->require($environment !== null && $environment->project_id === $target->projectId
                    && in_array($environment->status, ['active', 'paused', 'archived'], true)
                    && ($map === null || ($map->canonical_entity === 'project_environment' && (string) $map->canonical_id === (string) $environment->getKey()))
                    && (! $isTarget || (string) $environment->getKey() === $target->environmentId), 'environment_mapping_changed');
                $environmentResources = ProjectResource::query()->where('environment_id', $environment->getKey())->orderBy('id')
                    ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'project_id', 'product', 'resource_type', 'resource_id', 'status'])->toArray();
                $this->require($environment->status !== 'archived'
                    || ! collect($environmentResources)->contains(fn (array $other): bool => (string) $other['id'] !== (string) $resource->getKey()), 'shared_environment_reconciliation_required');
                if ($target->resourceType === 'application') {
                    foreach ([$resource->metadata, $environment->metadata, $map?->metadata] as $metadata) {
                        $this->require(! isset($metadata['source_application_id']) || (string) $metadata['source_application_id'] === $target->resourceId, 'environment_mapping_changed');
                    }
                }
            } else {
                $this->require($resource->environment_id === null && $target->environmentId === null
                    && ($map === null || ($map->canonical_entity === 'project' && (string) $map->canonical_id === $target->projectId)), 'resource_mapping_changed');
            }
            $resourceProvenance = $this->provenance($resource->metadata ?? []);
            $environmentProvenance = $environment === null ? null : $this->provenance($environment->metadata ?? []);
            if ($target->resourceType === 'application' && $environment !== null && $state->status !== 'archived'
                && ($resource->status === 'archived' || $environment->status === 'archived')
                && (($resource->status === 'archived' && ($resourceProvenance['archive_origin'] ?? null) === null)
                    || ($environment->status === 'archived' && ($environmentProvenance['archive_origin'] ?? null) === null))
                && $map?->batch_key === 'monitor-workspace-application-import-v1') {
                $this->require($this->legacyParentArchive($target, $resource, $environment, $map), 'archive_provenance_required');
                $resourceProvenance['archive_origin'] = 'parent_application';
                $environmentProvenance['archive_origin'] = 'parent_application';
            }
            $bindings[$key] = [
                'resource_id' => (string) $resource->getKey(), 'project_id' => $resource->project_id,
                'environment_id' => $resource->environment_id, 'resource_status' => $resource->status,
                'resource_provenance' => $resourceProvenance,
                'environment_status' => $environment?->status,
                'environment_provenance' => $environmentProvenance,
                'identity_id' => $map === null ? null : (string) $map->getKey(), 'canonical_entity' => $map?->canonical_entity,
                'canonical_id' => $map?->canonical_id,
                'identity_provenance' => $this->provenance($map?->metadata ?? []),
                'environment_resources' => $environmentResources ?? [],
            ];
        }
        $this->require(isset($seen[$target->resourceType.':'.$target->resourceId]), 'invalid_source_snapshot');
        if ($target->resourceType === 'application') {
            $knownChildren = ProjectResource::query()->where('product', $target->product)->where('resource_type', 'environment')
                ->where('metadata->source_application_id', $target->resourceId)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            foreach ($knownChildren as $child) {
                $this->require(isset($seen['environment:'.$child->resource_id]), 'environment_mapping_changed');
            }
            $knownIdentities = LegacyIdentityMap::query()->where('source_product', $target->product)->where('source_entity', 'environment')
                ->where('metadata->source_application_id', $target->resourceId)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            foreach ($knownIdentities as $identity) {
                $this->require(isset($seen['environment:'.$identity->source_id]), 'environment_mapping_changed');
            }
            $knownEnvironments = ProjectEnvironment::query()->where('metadata->source_product', $target->product)
                ->where('metadata->source_application_id', $target->resourceId)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            foreach ($knownEnvironments as $environment) {
                $key = 'environment:'.($environment->metadata['source_environment_id'] ?? '');
                $this->require(isset($bindings[$key]) && $bindings[$key]['environment_id'] === (string) $environment->getKey(), 'environment_mapping_changed');
            }
        }
        if ($target->resourceType === 'environment') {
            $this->require($target->parentApplicationId !== null, 'source_parent_mapping_changed');
            $parents = ProjectResource::query()->where('product', $target->product)->where('resource_type', 'application')
                ->where('resource_id', $target->parentApplicationId)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $parentMaps = LegacyIdentityMap::query()->where('source_product', $target->product)->where('source_entity', 'application')
                ->where('source_id', $target->parentApplicationId)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $this->require($parents->count() <= 1 && $parentMaps->count() <= 1 && ! ($parents->isEmpty() && $parentMaps->isNotEmpty()), 'source_parent_mapping_changed');
            $parent = $parents->first();
            $parentMap = $parentMaps->first();
            $this->require($parent === null || ($parent->project_id === $target->projectId && $parent->environment_id === null && $parent->status === 'active'), 'source_parent_mapping_changed');
            $this->require($parentMap === null || ($parentMap->status === 'reconciled' && $parentMap->canonical_entity === 'project'
                && (string) $parentMap->canonical_id === $target->projectId
                && (! isset($parentMap->metadata['project_resource_id']) || (string) $parentMap->metadata['project_resource_id'] === (string) $parent->getKey())), 'source_parent_mapping_changed');
            $bindings['_parent_application'] = ['source_id' => $target->parentApplicationId,
                'resource_id' => $parent === null ? null : (string) $parent->getKey(), 'project_id' => $parent?->project_id,
                'status' => $parent?->status, 'identity_id' => $parentMap === null ? null : (string) $parentMap->getKey()];
        }
        $products = ProjectProduct::query()->where('project_id', $target->projectId)->where('product', $target->product)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $this->require($products->count() === 1, 'product_mapping_changed');
        $product = $products->first();
        $root = $bindings[$target->resourceType.':'.$target->resourceId];
        $productArchiveOrigin = $product->metadata['archive_origin'] ?? null;
        $productApplicationId = $product->metadata['source_application_id'] ?? null;
        if ($product->status === 'inactive' && $productArchiveOrigin === null && $this->legacyProductArchive($target, $product, $root)) {
            $productArchiveOrigin = 'parent_application';
            $productApplicationId = $target->resourceId;
        }
        $this->require($product->status === 'active' || ($product->status === 'inactive'
            && $target->resourceType === 'application' && $target->product === 'monitor'
            && ($product->metadata['migration_source'] ?? null) === 'monitor'
            && $productArchiveOrigin === 'parent_application'
            && (string) $productApplicationId === $target->resourceId
            && $root['resource_status'] === 'archived' && $root['identity_id'] !== null), 'product_reconciliation_required');
        $bindings['_project_product'] = ['id' => (string) $product->getKey(), 'status' => $product->status, 'migration_source' => $product->metadata['migration_source'] ?? null, 'archive_origin' => $productArchiveOrigin, 'source_application_id' => $productApplicationId];
        $workspaceMaps = LegacyIdentityMap::query()->where('source_product', $target->product)
            ->where('source_entity', $target->sourceWorkspaceEntity)->where('source_id', $target->sourceWorkspaceId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'status', 'canonical_entity', 'canonical_id']);
        $this->require($workspaceMaps->count() === 1, 'workspace_mapping_changed');
        $bindings['_workspace_identity'] = $workspaceMaps->first()->toArray();
        if ($actorId !== null) {
            $identities = LegacyIdentityMap::query()->where('source_product', $target->product)->where('source_entity', 'user')
                ->where('canonical_id', $actorId)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
                ->get(['id', 'source_id', 'canonical_entity', 'canonical_id', 'status']);
            $this->require($identities->isNotEmpty() && $identities->every(fn (LegacyIdentityMap $map): bool => $map->status === 'reconciled' && $map->canonical_entity === 'user'), 'actor_mapping_changed');
            $bindings['_actor_identities'] = $identities->toArray();
        }
        ksort($bindings);

        return $bindings;
    }

    public function fingerprint(array $bindings): string
    {
        return hash('sha256', json_encode($bindings, JSON_THROW_ON_ERROR));
    }

    public function assertAttempt(ResourceRestorationAttempt $attempt, bool $lock = false): ResourceRestorationRequest
    {
        $request = ResourceRestorationRequest::query()->whereKey($attempt->requestId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $this->require($request !== null && $request->status === 'processing' && $request->attempts === $attempt->generation
            && hash_equals((string) $request->lease_token, $attempt->leaseToken)
            && $request->lease_expires_at?->isFuture() === true, 'restoration_lease_lost');
        $this->require($request->actor_id === $attempt->actorId && $request->target()->toArray() === $attempt->target->toArray()
            && $request->expected_revision === $attempt->expectedRevision
            && hash_equals($request->payload_hash, $attempt->payloadHash)
            && hash_equals($request->mapping_fingerprint, $attempt->mappingFingerprint), 'restoration_intent_changed');
        $actor = PlatformUser::query()->find($attempt->actorId);
        $this->require($actor !== null, 'actor_unavailable');
        $this->authorize($actor, $attempt->target, $lock);
        $bindings = $this->bindings($attempt->target, $request->states(), $lock, $attempt->actorId);
        $this->require(hash_equals($request->mapping_fingerprint, $this->fingerprint($bindings)), 'resource_mapping_changed');

        return $request;
    }

    private function legacyProductArchive(ResourceRestorationTarget $target, ProjectProduct $product, array $root): bool
    {
        if ($target->product !== 'monitor' || $target->resourceType !== 'application'
            || ($product->metadata['migration_source'] ?? null) !== 'monitor' || $root['identity_id'] === null) {
            return false;
        }
        $map = LegacyIdentityMap::query()->find($root['identity_id']);
        $resource = ProjectResource::query()->find($target->projectResourceId);
        $project = Project::query()->find($target->projectId);

        return $map?->batch_key === 'monitor-workspace-application-import-v1' && $map->imported_at !== null
            && $resource?->status === 'archived' && $resource->updated_at !== null && $resource->updated_at->lessThanOrEqualTo($map->imported_at)
            && $product->updated_at !== null && $product->updated_at->lessThanOrEqualTo($map->imported_at)
            && ($resource->metadata['deleted_at'] ?? null) !== null
            && ($project?->metadata['deleted_at'] ?? null) === ($resource->metadata['deleted_at'] ?? null)
            && (string) ($project?->metadata['source_application_id'] ?? '') === $target->resourceId;
    }

    private function legacyParentArchive(ResourceRestorationTarget $target, ProjectResource $resource, ProjectEnvironment $environment, LegacyIdentityMap $map): bool
    {
        $project = Project::query()->find($target->projectId);
        $applicationMap = LegacyIdentityMap::query()->where('source_product', $target->product)->where('source_entity', 'application')
            ->where('source_id', $target->resourceId)->where('canonical_entity', 'project')->where('canonical_id', $target->projectId)
            ->where('status', 'reconciled')->where('batch_key', 'monitor-workspace-application-import-v1')->first();
        if ($map->imported_at === null || $applicationMap === null || $project === null
            || (string) ($project->metadata['source_application_id'] ?? '') !== $target->resourceId
            || ($project->metadata['deleted_at'] ?? null) === null) {
            return false;
        }
        foreach ([$resource, $environment] as $row) {
            $metadata = $row->metadata ?? [];
            if (($metadata['archive_origin'] ?? null) !== null || ! array_key_exists('deleted_at', $metadata) || $metadata['deleted_at'] !== null
                || (string) ($metadata['source_application_id'] ?? '') !== $target->resourceId
                || $row->updated_at === null || $row->updated_at->greaterThan($map->imported_at)) {
                return false;
            }
        }

        return true;
    }

    private function provenance(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip(['source_product', 'source_workspace_id', 'source_application_id', 'source_environment_id', 'archive_origin', 'archive_revision', 'deleted_at', 'project_resource_id']));
    }

    private function require(bool $allowed, string $code): void
    {
        if (! $allowed) {
            throw new ResourceRestorationBlocked($code);
        }
    }
}
