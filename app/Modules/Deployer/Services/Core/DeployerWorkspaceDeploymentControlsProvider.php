<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\Deployer\WorkspaceDeployerDeploymentControlsProvider;
use App\Core\Data\Deployer\DeploymentControlsSnapshot;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment as CoreEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Actions\Environment\UpdateDeploymentControlsAction;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Support\Facades\DB;

/** Exposes only safe deployment-control state for one exactly mapped Deployer environment. */
final class DeployerWorkspaceDeploymentControlsProvider implements WorkspaceDeployerDeploymentControlsProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductWorkspaceAccess $nativeWorkspaceAccess,
        private readonly WorkspaceProjectAccess $projectAccess,
        private readonly UpdateDeploymentControlsAction $updateControls,
    ) {}

    public function snapshot(
        PlatformUser $actor,
        CoreWorkspace $workspace,
        CoreProject $project,
        CoreEnvironment $environment,
    ): DeploymentControlsSnapshot {
        $context = $this->resolve($actor, $workspace, $project, $environment);

        return $this->snapshotFor($context['coreProject'], $context['coreEnvironment'], $context['environment']);
    }

    public function update(
        PlatformUser $actor,
        CoreWorkspace $workspace,
        CoreProject $project,
        CoreEnvironment $environment,
        array $attributes,
    ): void {
        DB::connection('core')->transaction(function () use ($actor, $workspace, $project, $environment, $attributes): void {
            DB::connection('deployer')->transaction(function () use ($actor, $workspace, $project, $environment, $attributes): void {
                $context = $this->resolve($actor, $workspace, $project, $environment, lock: true);
                $this->updateControls->handle($context['environment'], $context['actor'], $attributes);
            }, attempts: 3);
        }, attempts: 3);
    }

    /**
     * Re-resolve both Core and Deployer authority. During a mutation, the caller holds
     * transactions on both databases and this method locks every existing authority row.
     *
     * @return array{actor: DeployerUser, organization: Organization, project: DeployerProject, environment: Environment, coreProject: CoreProject, coreEnvironment: CoreEnvironment}
     */
    private function resolve(
        PlatformUser $actor,
        CoreWorkspace $workspace,
        CoreProject $project,
        CoreEnvironment $environment,
        bool $lock = false,
    ): array {
        $lockRow = static fn ($query) => $lock ? $query->lockForUpdate() : $query;

        $currentActor = $lockRow(PlatformUser::query()->whereKey($actor->getKey())->where('status', 'active'))->first();
        abort_if($currentActor === null, 404);

        $currentWorkspace = $lockRow(CoreWorkspace::query()
            ->whereKey($workspace->getKey())->where('status', 'active')->whereNull('archived_at'))->first();
        abort_if($currentWorkspace === null, 404);

        $membership = $lockRow(WorkspaceMembership::query()
            ->where('workspace_id', $currentWorkspace->getKey())->where('user_id', $currentActor->getKey())
            ->currentlyActive())->first();
        abort_if($membership === null, 404);

        $productGrant = $lockRow(WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())->where('product', 'deployer')
            ->where('status', 'active')->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))->first();
        abort_if($productGrant === null || ! $this->projectAccess->hasProductAccess($membership, 'deployer'), 404);

        $currentProject = $lockRow(CoreProject::query()
            ->whereKey($project->getKey())->where('workspace_id', $currentWorkspace->getKey())
            ->where('status', 'active')->whereNull('archived_at'))->first();
        abort_if($currentProject === null || ! $this->projectAccess->canViewProject($currentActor, $currentProject)
            || ! $this->projectAccess->canAccessProductResource($currentActor, $currentProject, 'deployer'), 404);

        $projectMembership = $lockRow(ProjectMembership::query()
            ->where('project_id', $currentProject->getKey())->where('user_id', $currentActor->getKey())
            ->where('status', 'active')->whereNull('revoked_at'))->first();
        abort_if($projectMembership === null, 404);

        $projectProduct = $lockRow(ProjectProduct::query()
            ->where('project_id', $currentProject->getKey())->where('product', 'deployer')->where('status', 'active'))->first();
        abort_if($projectProduct === null, 404);

        $currentEnvironment = $lockRow(CoreEnvironment::query()
            ->whereKey($environment->getKey())->where('project_id', $currentProject->getKey())->where('status', 'active'))->first();
        abort_if($currentEnvironment === null, 404);

        $userMaps = $lockRow(LegacyIdentityMap::query()
            ->where('source_product', 'deployer')->where('source_entity', 'user')
            ->where('canonical_entity', 'user')->where('canonical_id', (string) $currentActor->getKey())
            ->where('status', 'reconciled'))->get(['source_id']);
        $organizationMaps = $lockRow(LegacyIdentityMap::query()
            ->where('source_product', 'deployer')->where('source_entity', 'organization')
            ->where('canonical_entity', 'workspace')->where('canonical_id', (string) $currentWorkspace->getKey())
            ->where('status', 'reconciled'))->get(['source_id']);
        abort_unless($userMaps->count() === 1 && $organizationMaps->count() === 1, 404);

        $organizationId = (string) $organizationMaps->sole()->source_id;
        $nativeActor = $lockRow(DeployerUser::query()->whereKey((string) $userMaps->sole()->source_id))->first();
        $organization = $lockRow(Organization::query()->whereKey($organizationId))->first();
        abort_if($nativeActor === null || $organization === null, 404);
        abort_unless($nativeActor->email_verified_at !== null, 403);
        $nativeMembership = $lockRow(DB::connection('deployer')->table('organization_user')
            ->where('organization_id', $organization->getKey())->where('user_id', $nativeActor->getKey()))->first();
        abort_unless((string) $organization->owner_id === (string) $nativeActor->getKey() || $nativeMembership !== null, 403);
        $accountFence = $lockRow(ProductDeletionFence::query()->where('kind', 'account')->where('source_id', (string) $nativeActor->getKey()))->first();
        $workspaceFence = $lockRow(ProductDeletionFence::query()->where('kind', 'workspace')->where('source_id', (string) $organization->getKey()))->first();
        abort_if($accountFence !== null || $workspaceFence !== null, 410);
        abort_unless($this->identities->canonicalIdForSource('deployer', 'organization', $organizationId, 'workspace')
            === (string) $currentWorkspace->getKey()
            && $this->nativeWorkspaceAccess->allows($currentActor, 'deployer', 'organization', $organizationId), 404);

        $projectMaps = $lockRow(ProjectResource::query()
            ->where('project_id', $currentProject->getKey())->where('product', 'deployer')
            ->where('resource_type', 'project')->where('status', 'active'))->get(['id', 'resource_id']);
        abort_unless($projectMaps->count() === 1, 404);
        $projectMapping = $projectMaps->sole();
        $nativeProjectId = (string) $projectMapping->resource_id;
        $nativeProjectMaps = $lockRow(ProjectResource::query()
            ->where('product', 'deployer')->where('resource_type', 'project')
            ->where('resource_id', $nativeProjectId)->where('status', 'active'))->get(['id', 'project_id', 'resource_id']);
        abort_unless($nativeProjectMaps->count() === 1
            && (string) $nativeProjectMaps->sole()->id === (string) $projectMapping->id, 404);

        $nativeProject = $lockRow(DeployerProject::query()
            ->whereKey($nativeProjectId)->where('organization_id', $organization->getKey()))->first();
        abort_if($nativeProject === null, 404);

        $environmentMaps = $lockRow(ProjectResource::query()
            ->where('project_id', $currentProject->getKey())->where('environment_id', $currentEnvironment->getKey())
            ->where('product', 'deployer')->where('resource_type', 'environment')->where('status', 'active'))
            ->get(['id', 'project_id', 'environment_id', 'resource_id']);
        abort_unless($environmentMaps->count() === 1, 404);
        $environmentMapping = $environmentMaps->sole();
        $nativeEnvironmentId = (string) $environmentMapping->resource_id;
        $nativeEnvironmentMaps = $lockRow(ProjectResource::query()
            ->where('product', 'deployer')->where('resource_type', 'environment')
            ->where('resource_id', $nativeEnvironmentId)->where('status', 'active'))
            ->get(['id', 'project_id', 'environment_id', 'resource_id']);
        abort_unless($nativeEnvironmentMaps->count() === 1
            && (string) $nativeEnvironmentMaps->sole()->id === (string) $environmentMapping->id
            && (string) $environmentMapping->project_id === (string) $currentProject->getKey()
            && (string) $environmentMapping->environment_id === (string) $currentEnvironment->getKey(), 404);

        $nativeEnvironment = $lockRow(Environment::query()
            ->whereKey($nativeEnvironmentId)->where('project_id', $nativeProject->getKey()))->first();
        abort_if($nativeEnvironment === null, 404);

        // The native policy reads current_organization_id. Keep the workspace-bound
        // context on this in-memory actor only; never persist a workspace switch.
        $nativeActor = clone $nativeActor;
        $nativeActor->setAttribute('current_organization_id', $organization->getKey());
        $nativeActor->unsetRelation('currentOrganization');
        abort_unless($nativeActor->can('update', $nativeEnvironment), 403);

        return [
            'actor' => $nativeActor,
            'organization' => $organization,
            'project' => $nativeProject,
            'environment' => $nativeEnvironment,
            'coreProject' => $currentProject,
            'coreEnvironment' => $currentEnvironment,
        ];
    }

    private function snapshotFor(CoreProject $project, CoreEnvironment $environment, Environment $source): DeploymentControlsSnapshot
    {
        $windowDays = array_values(array_map('intval', $source->deployment_window_days ?? []));

        return new DeploymentControlsSnapshot(
            projectId: (string) $project->getKey(),
            projectName: (string) $project->name,
            environmentId: (string) $environment->getKey(),
            environmentName: (string) $environment->name,
            environmentType: (string) $environment->environment_type,
            deploymentLocked: $source->deployment_locked_at !== null,
            deploymentLockReason: $source->deployment_lock_reason,
            deploymentWindowEnabled: $windowDays !== [] && $source->deployment_window_start !== null
                && $source->deployment_window_end !== null && $source->deployment_window_timezone !== null,
            deploymentWindowDays: $windowDays,
            deploymentWindowStart: $source->deployment_window_start === null ? null : substr((string) $source->deployment_window_start, 0, 5),
            deploymentWindowEnd: $source->deployment_window_end === null ? null : substr((string) $source->deployment_window_end, 0, 5),
            deploymentWindowTimezone: $source->deployment_window_timezone,
            deploymentStrategy: in_array($source->deployment_strategy, Environment::DEPLOYMENT_STRATEGIES, true)
                ? (string) $source->deployment_strategy : 'blue_green',
            rollingPauseSeconds: (int) ($source->rolling_pause_seconds ?? 2),
            automaticRollback: (bool) $source->automatic_rollback,
        );
    }
}
