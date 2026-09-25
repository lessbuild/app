<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\Deployer\WorkspaceDeployerConfigurationProvider;
use App\Core\Data\Deployer\DeployerEnvironmentConfigurationSnapshot;
use App\Core\Data\Deployer\DeployerProjectConfigurationDirectory;
use App\Core\Data\Deployer\DeployerProjectConfigurationSnapshot;
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
use App\Modules\Deployer\Actions\Environment\UpdateEnvironmentAction;
use App\Modules\Deployer\Actions\Project\UpdateProjectPreviewsAction;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Keeps Core configuration edits on the exact mapped Deployer resources and their native actions. */
final class DeployerWorkspaceConfigurationProvider implements WorkspaceDeployerConfigurationProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductWorkspaceAccess $nativeWorkspaceAccess,
        private readonly WorkspaceProjectAccess $projectAccess,
        private readonly UpdateProjectPreviewsAction $updateProjectPreviews,
        private readonly UpdateEnvironmentAction $updateEnvironment,
    ) {}

    public function projects(PlatformUser $actor, CoreWorkspace $workspace, ?string $search, int $page): DeployerProjectConfigurationDirectory
    {
        $context = $this->resolveWorkspace($actor, $workspace);
        $projectPage = $this->projectAccess->accessibleProductProjects(
            $context['platformActor'],
            $context['workspace'],
            'deployer',
        )
            ->whereHas('resources', fn (Builder $mapping) => $mapping
                ->where('product', 'deployer')->where('resource_type', 'project')->whereNull('environment_id')->where('status', 'active'))
            ->when(filled($search), fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes((string) $search, '%_\\').'%'))
            ->orderBy('name')->orderBy('id')
            ->paginate(20, ['projects.*'], 'page', max(1, $page));

        $snapshots = [];
        foreach ($projectPage as $project) {
            try {
                $projectContext = $this->resolveProject($context, $project, ability: 'update');
            } catch (HttpExceptionInterface $exception) {
                if (in_array($exception->getStatusCode(), [403, 404], true)) {
                    continue;
                }

                throw $exception;
            }

            $snapshots[] = $this->projectSnapshot($projectContext, includeEnvironments: false);
        }

        return new DeployerProjectConfigurationDirectory(
            projects: $snapshots,
            page: $projectPage->currentPage(),
            hasPreviousPage: $projectPage->currentPage() > 1,
            hasNextPage: $projectPage->hasMorePages(),
            search: filled($search) ? (string) $search : null,
        );
    }

    public function project(
        PlatformUser $actor,
        CoreWorkspace $workspace,
        CoreProject $project,
        ?string $environmentSearch,
        int $environmentPage,
    ): DeployerProjectConfigurationSnapshot {
        $context = $this->resolveWorkspace($actor, $workspace);
        $projectContext = $this->resolveProject($context, $project, ability: 'update');

        return $this->projectSnapshot($projectContext, $environmentSearch, $environmentPage);
    }

    public function updateProjectPreviews(
        PlatformUser $actor,
        CoreWorkspace $workspace,
        CoreProject $project,
        array $attributes,
    ): void {
        DB::connection('core')->transaction(function () use ($actor, $workspace, $project, $attributes): void {
            DB::connection('deployer')->transaction(function () use ($actor, $workspace, $project, $attributes): void {
                $context = $this->resolveWorkspace($actor, $workspace, lock: true);
                $projectContext = $this->resolveProject($context, $project, ability: 'update', lock: true);
                $this->updateProjectPreviews->handle($projectContext['nativeProject'], $attributes);
            }, attempts: 3);
        }, attempts: 3);
    }

    public function environment(
        PlatformUser $actor,
        CoreWorkspace $workspace,
        CoreProject $project,
        CoreEnvironment $environment,
    ): DeployerEnvironmentConfigurationSnapshot {
        $context = $this->resolveWorkspace($actor, $workspace);
        $projectContext = $this->resolveProject($context, $project, ability: 'update');
        $environmentContext = $this->resolveEnvironment($projectContext, $environment, ability: 'update');

        return $this->environmentSnapshot($projectContext, $environmentContext);
    }

    public function updateEnvironment(
        PlatformUser $actor,
        CoreWorkspace $workspace,
        CoreProject $project,
        CoreEnvironment $environment,
        array $attributes,
    ): void {
        DB::connection('core')->transaction(function () use ($actor, $workspace, $project, $environment, $attributes): void {
            DB::connection('deployer')->transaction(function () use ($actor, $workspace, $project, $environment, $attributes): void {
                $context = $this->resolveWorkspace($actor, $workspace, lock: true);
                $projectContext = $this->resolveProject($context, $project, ability: 'update', lock: true);
                $environmentContext = $this->resolveEnvironment($projectContext, $environment, ability: 'update', lock: true);
                $nativeEnvironment = $environmentContext['nativeEnvironment'];
                $nativeActor = $context['nativeActor'];
                abort_if(array_key_exists('type', $attributes), 422);

                // Commands and server/site placement remain private to Deployer. Preserve the current
                // values in the action payload and validate them as the native environment request does.
                $completeAttributes = [
                    'name' => $attributes['name'],
                    'type' => $nativeEnvironment->type,
                    'branch' => $attributes['branch'],
                    'runtime_type' => $nativeEnvironment->runtime_type ?: 'php',
                    'runtime_version' => $nativeEnvironment->runtime_version,
                    'build_command' => $nativeEnvironment->build_command,
                    'start_command' => $nativeEnvironment->start_command,
                    'container_port' => $nativeEnvironment->container_port,
                    'dockerfile_path' => $nativeEnvironment->dockerfile_path,
                    'server_id' => $nativeEnvironment->server_id,
                    'website_id' => $nativeEnvironment->website_id,
                    'is_protected' => (bool) $nativeEnvironment->is_protected,
                    'requires_deployment_approval' => (bool) $nativeEnvironment->requires_deployment_approval,
                    'post_deployment_observation_minutes' => $attributes['post_deployment_observation_minutes'],
                    'minimum_replicas' => $attributes['minimum_replicas'],
                    'maximum_replicas' => $attributes['maximum_replicas'],
                    'hibernate_after_minutes' => $attributes['hibernate_after_minutes'],
                ];

                Validator::make($completeAttributes, $this->nativeEnvironmentRules($nativeActor, $projectContext['organization']))
                    ->validate();

                $this->updateEnvironment->handle($nativeEnvironment, $completeAttributes);
            }, attempts: 3);
        }, attempts: 3);
    }

    /** @return array{platformActor: PlatformUser, workspace: CoreWorkspace, nativeActor: DeployerUser, organization: Organization} */
    private function resolveWorkspace(PlatformUser $actor, CoreWorkspace $workspace, bool $lock = false): array
    {
        $lockRow = static fn ($query) => $lock ? $query->lockForUpdate() : $query;
        $platformActor = $lockRow(PlatformUser::query()->whereKey($actor->getKey())->where('status', 'active'))->first();
        abort_if($platformActor === null, 404);
        $currentWorkspace = $lockRow(CoreWorkspace::query()
            ->whereKey($workspace->getKey())->where('status', 'active')->whereNull('archived_at'))->first();
        abort_if($currentWorkspace === null, 404);

        $membership = $lockRow(WorkspaceMembership::query()
            ->where('workspace_id', $currentWorkspace->getKey())->where('user_id', $platformActor->getKey())
            ->currentlyActive())->first();
        abort_if($membership === null, 404);
        $grant = $lockRow(WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())->where('product', 'deployer')
            ->where('status', 'active')->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))->first();
        abort_if($grant === null || ! $this->projectAccess->hasProductAccess($membership, 'deployer'), 404);

        $userMaps = $lockRow(LegacyIdentityMap::query()
            ->where('source_product', 'deployer')->where('source_entity', 'user')
            ->where('canonical_entity', 'user')->where('canonical_id', (string) $platformActor->getKey())
            ->where('status', 'reconciled'))->get(['source_id']);
        $organizationMaps = $lockRow(LegacyIdentityMap::query()
            ->where('source_product', 'deployer')->where('source_entity', 'organization')
            ->where('canonical_entity', 'workspace')->where('canonical_id', (string) $currentWorkspace->getKey())
            ->where('status', 'reconciled'))->get(['source_id']);
        abort_unless($userMaps->count() === 1 && $organizationMaps->count() === 1, 404);

        $userReverseMaps = $lockRow(LegacyIdentityMap::query()
            ->where('source_product', 'deployer')->where('source_entity', 'user')
            ->where('source_id', (string) $userMaps->sole()->source_id)->where('canonical_entity', 'user')
            ->where('status', 'reconciled'))->get(['canonical_id']);
        $organizationReverseMaps = $lockRow(LegacyIdentityMap::query()
            ->where('source_product', 'deployer')->where('source_entity', 'organization')
            ->where('source_id', (string) $organizationMaps->sole()->source_id)->where('canonical_entity', 'workspace')
            ->where('status', 'reconciled'))->get(['canonical_id']);
        abort_unless($userReverseMaps->count() === 1
            && (string) $userReverseMaps->sole()->canonical_id === (string) $platformActor->getKey()
            && $organizationReverseMaps->count() === 1
            && (string) $organizationReverseMaps->sole()->canonical_id === (string) $currentWorkspace->getKey(), 404);

        $nativeActor = $lockRow(DeployerUser::query()->whereKey((string) $userMaps->sole()->source_id))->first();
        $organization = $lockRow(Organization::query()->whereKey((string) $organizationMaps->sole()->source_id))->first();
        abort_if($nativeActor === null || $organization === null, 404);
        abort_unless($nativeActor->email_verified_at !== null, 403);
        $nativeMembership = $lockRow(DB::connection('deployer')->table('organization_user')
            ->where('organization_id', $organization->getKey())->where('user_id', $nativeActor->getKey()))->first();
        abort_unless((string) $organization->owner_id === (string) $nativeActor->getKey() || $nativeMembership !== null, 403);

        $accountFence = $lockRow(ProductDeletionFence::query()->where('kind', 'account')->where('source_id', (string) $nativeActor->getKey()))->first();
        $workspaceFence = $lockRow(ProductDeletionFence::query()->where('kind', 'workspace')->where('source_id', (string) $organization->getKey()))->first();
        abort_if($accountFence !== null || $workspaceFence !== null, 410);

        abort_unless($this->identities->canonicalIdForSource('deployer', 'organization', (string) $organization->getKey(), 'workspace')
            === (string) $currentWorkspace->getKey()
            && $this->nativeWorkspaceAccess->allows($platformActor, 'deployer', 'organization', (string) $organization->getKey()), 404);

        $nativeActor = clone $nativeActor;
        $nativeActor->setAttribute('current_organization_id', $organization->getKey());
        $nativeActor->unsetRelation('currentOrganization');

        return [
            'platformActor' => $platformActor,
            'workspace' => $currentWorkspace,
            'nativeActor' => $nativeActor,
            'organization' => $organization,
        ];
    }

    /** @return array{coreProject: CoreProject, nativeProject: DeployerProject, organization: Organization, nativeActor: DeployerUser} */
    private function resolveProject(array $workspaceContext, CoreProject $project, string $ability, bool $lock = false): array
    {
        $lockRow = static fn ($query) => $lock ? $query->lockForUpdate() : $query;
        $currentProject = $lockRow(CoreProject::query()
            ->whereKey($project->getKey())->where('workspace_id', $workspaceContext['workspace']->getKey())
            ->where('status', 'active')->whereNull('archived_at'))->first();
        abort_if($currentProject === null
            || ! $this->projectAccess->canViewProject($workspaceContext['platformActor'], $currentProject)
            || ! $this->projectAccess->canAccessProductResource($workspaceContext['platformActor'], $currentProject, 'deployer'), 404);

        $projectMembership = $lockRow(ProjectMembership::query()
            ->where('project_id', $currentProject->getKey())->where('user_id', $workspaceContext['platformActor']->getKey())
            ->where('status', 'active')->whereNull('revoked_at'))->first();
        $product = $lockRow(ProjectProduct::query()
            ->where('project_id', $currentProject->getKey())->where('product', 'deployer')->where('status', 'active'))->first();
        abort_if($projectMembership === null || $product === null, 404);

        $projectMappings = $lockRow(ProjectResource::query()
            ->where('project_id', $currentProject->getKey())->whereNull('environment_id')
            ->where('product', 'deployer')->where('resource_type', 'project')->where('status', 'active'))
            ->get(['id', 'project_id', 'environment_id', 'resource_id']);
        abort_unless($projectMappings->count() === 1, 404);
        $mapping = $projectMappings->sole();
        $reverseMappings = $lockRow(ProjectResource::query()
            ->where('product', 'deployer')->where('resource_type', 'project')->where('status', 'active')
            ->where('resource_id', (string) $mapping->resource_id))
            ->get(['id', 'project_id', 'environment_id', 'resource_id']);
        abort_unless($reverseMappings->count() === 1
            && (string) $reverseMappings->sole()->id === (string) $mapping->id
            && (string) $reverseMappings->sole()->project_id === (string) $currentProject->getKey()
            && $reverseMappings->sole()->environment_id === null, 404);

        $nativeProject = $lockRow(DeployerProject::query()
            ->whereKey((string) $mapping->resource_id)
            ->where('organization_id', $workspaceContext['organization']->getKey()))->first();
        abort_if($nativeProject === null, 404);
        abort_unless($workspaceContext['nativeActor']->can($ability, $nativeProject), 403);

        return [
            'coreProject' => $currentProject,
            'nativeProject' => $nativeProject,
            'organization' => $workspaceContext['organization'],
            'nativeActor' => $workspaceContext['nativeActor'],
        ];
    }

    /** @return array{coreEnvironment: CoreEnvironment, nativeEnvironment: Environment} */
    private function resolveEnvironment(array $projectContext, CoreEnvironment $environment, string $ability, bool $lock = false): array
    {
        $lockRow = static fn ($query) => $lock ? $query->lockForUpdate() : $query;
        $coreEnvironment = $lockRow(CoreEnvironment::query()
            ->whereKey($environment->getKey())->where('project_id', $projectContext['coreProject']->getKey())
            ->where('status', 'active'))->first();
        abort_if($coreEnvironment === null, 404);

        $mappings = $lockRow(ProjectResource::query()
            ->where('project_id', $projectContext['coreProject']->getKey())
            ->where('environment_id', $coreEnvironment->getKey())
            ->where('product', 'deployer')->where('resource_type', 'environment')->where('status', 'active'))
            ->get(['id', 'project_id', 'environment_id', 'resource_id']);
        abort_unless($mappings->count() === 1, 404);
        $mapping = $mappings->sole();
        $reverseMappings = $lockRow(ProjectResource::query()
            ->where('product', 'deployer')->where('resource_type', 'environment')->where('status', 'active')
            ->where('resource_id', (string) $mapping->resource_id))
            ->get(['id', 'project_id', 'environment_id', 'resource_id']);
        abort_unless($reverseMappings->count() === 1
            && (string) $reverseMappings->sole()->id === (string) $mapping->id
            && (string) $reverseMappings->sole()->project_id === (string) $projectContext['coreProject']->getKey()
            && (string) $reverseMappings->sole()->environment_id === (string) $coreEnvironment->getKey(), 404);

        $nativeEnvironment = $lockRow(Environment::query()
            ->whereKey((string) $mapping->resource_id)
            ->where('project_id', $projectContext['nativeProject']->getKey()))->first();
        abort_if($nativeEnvironment === null, 404);
        // A native type edit without a matching Core update would make the canonical
        // environment disagree across products. Existing mismatches also stay closed.
        abort_unless((string) $coreEnvironment->environment_type === (string) $nativeEnvironment->type, 404);
        abort_unless($projectContext['nativeActor']->can($ability, $nativeEnvironment), 403);

        return ['coreEnvironment' => $coreEnvironment, 'nativeEnvironment' => $nativeEnvironment];
    }

    private function projectSnapshot(
        array $projectContext,
        ?string $environmentSearch = null,
        int $environmentPage = 1,
        bool $includeEnvironments = true,
    ): DeployerProjectConfigurationSnapshot {
        $environments = [];
        $environmentPaginator = null;

        if ($includeEnvironments) {
            $environmentPaginator = CoreEnvironment::query()
                ->where('project_id', $projectContext['coreProject']->getKey())->where('status', 'active')
                ->whereHas('resources', fn (Builder $mapping) => $mapping
                    ->where('product', 'deployer')->where('resource_type', 'environment')->where('status', 'active'))
                ->when(filled($environmentSearch), fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes((string) $environmentSearch, '%_\\').'%'))
                ->orderBy('name')->orderBy('id')
                ->paginate(20, ['*'], 'environment_page', max(1, $environmentPage));

            foreach ($environmentPaginator as $coreEnvironment) {
                try {
                    $environmentContext = $this->resolveEnvironment($projectContext, $coreEnvironment, ability: 'update');
                } catch (HttpExceptionInterface $exception) {
                    if (in_array($exception->getStatusCode(), [403, 404], true)) {
                        continue;
                    }

                    throw $exception;
                }

                $environments[] = $this->environmentSnapshot($projectContext, $environmentContext);
            }
        }

        $nativeProject = $projectContext['nativeProject'];

        return new DeployerProjectConfigurationSnapshot(
            projectId: (string) $projectContext['coreProject']->getKey(),
            projectName: (string) $projectContext['coreProject']->name,
            previewEnabled: (bool) $nativeProject->preview_enabled,
            previewDomain: $nativeProject->preview_domain === null ? null : (string) $nativeProject->preview_domain,
            previewTtlHours: (int) ($nativeProject->preview_ttl_hours ?? 72),
            environments: $environments,
            environmentPage: $environmentPaginator?->currentPage() ?? 1,
            hasPreviousEnvironmentPage: $environmentPaginator !== null && $environmentPaginator->currentPage() > 1,
            hasNextEnvironmentPage: $environmentPaginator?->hasMorePages() ?? false,
            environmentSearch: filled($environmentSearch) ? (string) $environmentSearch : null,
        );
    }

    private function environmentSnapshot(array $projectContext, array $environmentContext): DeployerEnvironmentConfigurationSnapshot
    {
        $environment = $environmentContext['nativeEnvironment'];
        $coreEnvironment = $environmentContext['coreEnvironment'];

        return new DeployerEnvironmentConfigurationSnapshot(
            projectId: (string) $projectContext['coreProject']->getKey(),
            projectName: (string) $projectContext['coreProject']->name,
            environmentId: (string) $coreEnvironment->getKey(),
            environmentName: (string) $environment->name,
            environmentType: (string) $environment->type,
            branch: (string) $environment->branch,
            runtimeType: (string) ($environment->runtime_type ?: 'php'),
            runtimeVersion: $environment->runtime_version === null ? null : (string) $environment->runtime_version,
            minimumReplicas: (int) ($environment->minimum_replicas ?? 1),
            maximumReplicas: (int) ($environment->maximum_replicas ?? 1),
            hibernateAfterMinutes: $environment->hibernate_after_minutes === null ? null : (int) $environment->hibernate_after_minutes,
            postDeploymentObservationMinutes: $environment->post_deployment_observation_minutes === null
                ? null : (int) $environment->post_deployment_observation_minutes,
        );
    }

    /** @return array<string, list<mixed>> */
    private function nativeEnvironmentRules(DeployerUser $actor, Organization $organization): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(Environment::TYPES)],
            'branch' => ['required', 'string', 'max:255'],
            'runtime_type' => ['required', Rule::in(Environment::RUNTIME_TYPES)],
            'runtime_version' => ['nullable', 'string', 'max:20', 'regex:/\A[0-9]+(?:\.[0-9]+){0,2}\z/'],
            'build_command' => ['nullable', 'string', 'max:2000'],
            'start_command' => ['nullable', 'string', 'max:2000', 'required_if:runtime_type,node,python'],
            'container_port' => ['nullable', 'integer', 'between:1,65535', 'required_if:runtime_type,node,python,docker'],
            'dockerfile_path' => ['nullable', 'string', 'max:255', 'regex:/\A(?!\/)(?!.*\.\.)(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\z/', 'required_if:runtime_type,docker'],
            'server_id' => ['nullable', Rule::exists('deployer.servers', 'id')
                ->whereIn('id', $actor->workspaceServers()->pluck('servers.id'))
                ->where('organization_id', $organization->getKey())],
            'website_id' => ['nullable', Rule::exists('deployer.websites', 'id')
                ->whereIn('id', $actor->workspaceWebsites()->pluck('websites.id'))
                ->where('organization_id', $organization->getKey())],
            'is_protected' => ['required', 'boolean'],
            'requires_deployment_approval' => ['required', 'boolean'],
            'post_deployment_observation_minutes' => ['nullable', 'integer', Rule::in(Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES)],
            'minimum_replicas' => ['required', 'integer', 'between:1,20'],
            'maximum_replicas' => ['required', 'integer', 'between:1,20', 'gte:minimum_replicas'],
            'hibernate_after_minutes' => ['nullable', 'integer', Rule::in([5, 15, 30, 60, 120, 1440])],
        ];
    }
}
