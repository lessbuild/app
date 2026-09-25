<?php

namespace App\Core\Services\Blueprints;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectBlueprintStep;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;

final class BlueprintAuthority
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    /** @return array{PlatformUser, Workspace, Project} */
    public function authorize(BlueprintTarget $target, string $product): array
    {
        $actor = PlatformUser::query()->find($target->actorId);
        $workspace = Workspace::query()->find($target->workspaceId);
        $project = Project::query()->where('workspace_id', $target->workspaceId)->find($target->projectId);
        $this->require($actor !== null && $workspace !== null && $project !== null, 'authority_unavailable');
        $this->require($this->access->canManageWorkspace($actor, $workspace)
            && $this->access->canLinkProductResource($actor, $project, $product), 'authority_changed');
        $this->require((bool) config('platform.products.'.$product.'.enabled', false), 'product_unavailable');
        $this->require(! ProjectProduct::query()->where('project_id', $project->getKey())->where('product', $product)
            ->whereIn('status', ['inactive', 'archived', 'deleting', 'deleted'])->exists(), 'resource_bindings_changed');
        foreach ($target->environments as $environment) {
            if ($environment['id'] === null) {
                continue;
            }
            $this->require(ProjectEnvironment::query()->whereKey($environment['id'])->where('project_id', $project->getKey())
                ->where('status', 'active')->where('environment_type', $environment['type'])->exists(), 'environment_changed');
        }

        return [$actor, $workspace, $project];
    }

    /** Recheck a lease before and after each native transaction, including completed receipt replay. */
    public function assertAttempt(BlueprintStepAttempt $attempt): ProjectBlueprintStep
    {
        $step = ProjectBlueprintStep::query()->find($attempt->stepId);
        $this->require($step !== null && $step->project_blueprint_run_id === $attempt->runId
            && $step->product === $attempt->product && $step->status === 'processing'
            && $step->generation === $attempt->generation && $step->lease_expires_at?->isFuture() === true
            && hash_equals((string) $step->lease_token, $attempt->leaseToken), 'worker_lease_lost');
        $this->require(hash_equals($step->payload_hash, $attempt->payloadHash)
            && BlueprintFingerprint::make($step->target) === BlueprintFingerprint::make($attempt->target->toArray())
            && BlueprintFingerprint::make($step->configuration) === BlueprintFingerprint::make($attempt->configuration)
            && BlueprintFingerprint::make($step->native_authority) === BlueprintFingerprint::make($attempt->nativeAuthority), 'intent_changed');
        $this->authorize($attempt->target, $attempt->product);
        $this->require(hash_equals($step->core_binding_hash, $this->bindingHash($attempt->target, $attempt->product)), 'resource_bindings_changed');

        return $step;
    }

    public function bindingHash(BlueprintTarget $target, string $product): string
    {
        $workspaceEntity = $product === 'deployer' ? 'organization' : 'workspace';
        $identities = LegacyIdentityMap::query()->where('source_product', $product)
            ->where(fn ($query) => $query->where(fn ($actor) => $actor->where('source_entity', 'user')->where('canonical_entity', 'user')->where('canonical_id', $target->actorId))
                ->orWhere(fn ($workspace) => $workspace->where('source_entity', $workspaceEntity)->where('canonical_entity', 'workspace')->where('canonical_id', $target->workspaceId)))
            ->orderBy('id')->get(['id', 'source_entity', 'source_id', 'canonical_entity', 'canonical_id', 'status'])->toArray();
        $resources = ProjectResource::query()->where('project_id', $target->projectId)->where('product', $product)->orderBy('id')
            ->get(['id', 'product', 'resource_type', 'resource_id', 'environment_id', 'status'])->toArray();

        return BlueprintFingerprint::make(['identities' => $identities, 'resources' => $resources, 'environments' => $target->environments]);
    }

    private function require(bool $allowed, string $reason): void
    {
        if (! $allowed) {
            throw new BlueprintBlocked($reason);
        }
    }
}
