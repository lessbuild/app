<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorServiceObjectiveAdministrationProvider;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Data\Monitor\MonitorServiceObjectiveSnapshot;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeServiceLevelObjective;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Core desired-state service objective administration delegated to Monitor's native action. */
final class MonitorServiceObjectiveAdministrationProvider implements WorkspaceMonitorServiceObjectiveAdministrationProvider
{
    private readonly MonitorAdministrationContext $context;

    public function __construct(
        LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        ProductWorkspaceAccess $productAccess,
        private readonly ChangeServiceLevelObjective $changes,
    ) {
        $this->context = new MonitorAdministrationContext($identities, $workspaceAccess, $productAccess);
    }

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace, array $filters = []): ?MonitorServiceObjectiveSnapshot
    {
        $this->context->resetReferences();
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return null;
        }
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $resolved;

        $mappedEnvironments = $this->mappedEnvironments($user, $workspace, $sourceWorkspace, $actor);
        $environmentIds = $mappedEnvironments->keys()->all();
        $canManage = $this->canManage($user, $workspace, $actor, $sourceWorkspace);
        $page = max(1, min(100000, (int) ($filters['slo_page'] ?? 1)));
        $search = trim((string) ($filters['slo_search'] ?? ''));
        $items = ServiceLevelObjective::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->whereIn('environment_id', $environmentIds)
            ->with('environment.application')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where('name', 'like', $term)->orWhere('service', 'like', $term)->orWhere('route', 'like', $term)
                    ->orWhereHas('environment', fn ($environment) => $environment->where('name', 'like', $term)
                        ->orWhereHas('application', fn ($application) => $application->where('name', 'like', $term)));
            }))
            ->orderByDesc('enabled')->orderBy('name')->orderBy('id')
            ->paginate(20, ['*'], 'slo_page', $page)->withQueryString()
            ->through(function (ServiceLevelObjective $objective) use ($sourceWorkspace, $mappedEnvironments, $canManage): array {
                $version = $this->version($objective);
                $environment = $mappedEnvironments->get((string) $objective->environment_id);

                return [
                    'reference' => $this->context->reference('service-objective', $objective->getKey(), $sourceWorkspace),
                    'form_key' => hash_hmac('sha256', 'monitor-service-objective:'.$sourceWorkspace->getKey().':'.$objective->getKey(), (string) config('app.key')),
                    'version' => $version,
                    'can_mutate' => $canManage && $version !== null && $environment !== null,
                    'environment_reference' => $environment === null ? '' : $this->context->reference('environment', $environment->getKey(), $sourceWorkspace),
                    'environment' => $environment === null ? '' : $this->environmentLabel($environment),
                    'name' => (string) $objective->name,
                    'indicator' => (string) $objective->indicator,
                    'service' => (string) ($objective->service ?? ''),
                    'route' => (string) ($objective->route ?? ''),
                    'target' => number_format((float) $objective->target, 3, '.', ''),
                    'window_days' => (int) $objective->window_days,
                    'latency_threshold_ms' => $objective->latency_threshold_ms === null ? '' : (string) $objective->latency_threshold_ms,
                    'status_min' => (int) $objective->status_min,
                    'status_max' => (int) $objective->status_max,
                    'enabled' => (bool) $objective->enabled,
                ];
            });

        $environmentOptions = $mappedEnvironments->map(fn (Environment $environment): array => [
            'reference' => $this->context->reference('environment', $environment->getKey(), $sourceWorkspace),
            'label' => $this->environmentLabel($environment),
        ])->values()->all();

        return new MonitorServiceObjectiveSnapshot($items, $environmentOptions, $canManage);
    }

    public function saveObjective(PlatformUser $user, CoreWorkspace $workspace, ?string $objectiveReference, array $data): MonitorMutationResult
    {
        $indicator = is_string($data['indicator'] ?? null) ? $data['indicator'] : null;
        $values = Validator::make($data, [
            'version' => $objectiveReference === null
                ? ['prohibited']
                : ['required', 'string', 'regex:/\\A\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?\\z/'],
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\\x00-\\x1F\\x7F]/u'],
            'environment_reference' => ['required', 'string', 'max:4096'],
            'indicator' => ['required', Rule::in(['availability', 'latency'])],
            'service' => ['nullable', 'string', 'max:100', 'not_regex:/[\\x00-\\x1F\\x7F]/u'],
            'route' => ['nullable', 'string', 'max:255', 'not_regex:/[\\x00-\\x1F\\x7F]/u'],
            'target' => ['required', 'numeric', 'decimal:1,3', 'between:0.001,99.999'],
            'window_days' => ['required', 'integer', Rule::in([7, 30])],
            'latency_threshold_ms' => [Rule::excludeIf($indicator !== 'latency'), 'required', 'numeric', 'gt:0', 'max:600000'],
            'status_min' => [Rule::excludeIf($indicator !== 'availability'), 'required', 'integer', 'between:100,599'],
            'status_max' => [Rule::excludeIf($indicator !== 'availability'), 'required', 'integer', 'between:100,599', 'gte:status_min'],
            'enabled' => ['required', 'boolean'],
        ])->validate();
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $this->assertCoreManager($user, $workspace);

        $id = $objectiveReference === null
            ? null
            : $this->context->sourceId($objectiveReference, 'service-objective', $sourceWorkspace);
        $environmentId = $this->context->sourceId($values['environment_reference'], 'environment', $sourceWorkspace);
        unset($values['environment_reference']);
        $version = $values['version'] ?? null;
        unset($values['version']);

        $environment = Environment::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->findOrFail((int) $environmentId);
        abort_if($this->mappedEnvironments($user, $workspace, $sourceWorkspace, $actor)->has((string) $environment->getKey()) === false, 404);
        $values['environment_id'] = (int) $environment->getKey();

        $saved = $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $version, $values, $actor, $user, $workspace): ServiceLevelObjective {
            $this->assertCoreManager($user, $workspace);
            abort_unless($this->mappedEnvironments($user, $workspace, $locked, $actor)->has((string) $values['environment_id']), 404);
            $objective = null;
            if ($id !== null) {
                $objective = ServiceLevelObjective::forWorkspace($locked)->visibleTo($actor, $locked)
                    ->lockForUpdate()->findOrFail($id);
                $currentVersion = $this->version($objective);
                abort_unless($currentVersion !== null && is_string($version) && hash_equals($currentVersion, $version), 409,
                    'This service objective changed. Reload the page before saving.');
                if ((string) $objective->environment_id !== (string) $values['environment_id']) {
                    throw ValidationException::withMessages(['environment_reference' => 'The environment cannot be changed. Create a separate objective.']);
                }
            }

            $saved = $this->changes->save($locked, $actor, $values, $objective);
            abort_unless((string) $saved->environment_id === (string) $values['environment_id'], 404);
            abort_unless($this->mappedEnvironments($user, $workspace, $locked, $actor)->has((string) $saved->environment_id), 404);
            $this->assertCoreManager($user, $workspace);

            return $saved;
        });

        return new MonitorMutationResult(true, $id === null ? 'Service objective created.' : 'Service objective updated.',
            $this->context->reference('service-objective', $saved->getKey(), $sourceWorkspace));
    }

    public function archiveObjective(PlatformUser $user, CoreWorkspace $workspace, string $objectiveReference, string $version, bool $confirmArchive): MonitorMutationResult
    {
        abort_unless(preg_match('/\\A\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?\\z/', $version) === 1, 422);
        abort_unless($confirmArchive, 422, 'Confirm that you want to archive this service objective.');
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $this->assertCoreManager($user, $workspace);
        $id = $this->context->sourceId($objectiveReference, 'service-objective', $sourceWorkspace);

        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $version, $actor, $user, $workspace): void {
            $this->assertCoreManager($user, $workspace);
            $objective = ServiceLevelObjective::forWorkspace($locked)->visibleTo($actor, $locked)
                ->lockForUpdate()->findOrFail($id);
            abort_unless($this->mappedEnvironments($user, $workspace, $locked, $actor)->has((string) $objective->environment_id), 404);
            $currentVersion = $this->version($objective);
            abort_unless($currentVersion !== null && hash_equals($currentVersion, $version), 409,
                'This service objective changed. Reload the page before archiving.');

            $this->changes->archive($objective, $locked, $actor);
            $this->assertCoreManager($user, $workspace);
        });

        return new MonitorMutationResult(true, 'Service objective archived.');
    }

    /** @return Collection<string, Environment> */
    private function mappedEnvironments(PlatformUser $user, CoreWorkspace $workspace, Workspace $sourceWorkspace, User $actor): Collection
    {
        $projects = $this->workspaceAccess->accessibleProductProjects($user, $workspace, 'monitor')
            ->orderBy('projects.id')->get(['projects.id', 'projects.name'])->keyBy(fn (Project $project): string => (string) $project->getKey());
        if ($projects->isEmpty()) {
            return collect();
        }

        $productRows = ProjectProduct::query()->whereIn('project_id', $projects->keys())->where('product', 'monitor')->get()->groupBy('project_id');
        $projectIds = $projects->filter(fn (Project $project): bool => ($productRows->get((string) $project->getKey())?->count() ?? 0) === 1
            && $productRows->get((string) $project->getKey())->sole()->status === 'active')->keys()->map(strval(...))->all();
        if ($projectIds === []) {
            return collect();
        }

        $applications = Application::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)->get()->keyBy(fn (Application $application): string => (string) $application->getKey());
        $applicationResources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'application')
            ->whereIn('resource_id', $applications->keys())->get()->groupBy('resource_id');
        $applicationIdentities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'application')
            ->whereIn('source_id', $applications->keys())->get()->groupBy('source_id');
        $applicationClaims = $this->canonicalResourceClaims('application', 'application', 'project', $projectIds, 'project_id');
        $applicationIdentityClaims = $this->canonicalIdentityClaims('application', 'project', $projectIds);
        $applicationProjectIds = [];
        foreach ($applications as $applicationId => $application) {
            $resources = $applicationResources->get((string) $applicationId, collect());
            $identities = $applicationIdentities->get((string) $applicationId, collect());
            if ($resources->count() !== 1 || $identities->count() !== 1) {
                continue;
            }
            $resource = $resources->sole();
            $identity = $identities->sole();
            $project = $projects->get((string) $resource->project_id);
            if ($project === null || $resource->status !== 'active' || $resource->environment_id !== null
                || $identity->status !== 'reconciled' || $identity->canonical_entity !== 'project'
                || (string) $identity->canonical_id !== (string) $project->getKey()) {
                continue;
            }
            $projectClaims = $applicationClaims->get((string) $project->getKey(), collect());
            $identityClaims = $applicationIdentityClaims->get((string) $project->getKey(), collect());
            if ($projectClaims->count() !== 1 || (string) $projectClaims->sole()->getKey() !== (string) $resource->getKey()
                || $identityClaims->count() !== 1 || (string) $identityClaims->sole()->source_id !== (string) $applicationId) {
                continue;
            }
            $applicationProjectIds[(string) $application->getKey()] = (string) $project->getKey();
        }

        if ($applicationProjectIds === []) {
            return collect();
        }
        $environments = Environment::forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->with('application')->get()->filter(fn (Environment $environment): bool => isset($applicationProjectIds[(string) $environment->application_id]));
        $environmentIds = $environments->map(fn (Environment $environment): string => (string) $environment->getKey())->all();
        $resources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->whereIn('resource_id', $environmentIds)
            ->get()->groupBy('resource_id');
        $identities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'environment')
            ->whereIn('source_id', $environmentIds)->get()->groupBy('source_id');
        $canonicalEnvironmentIds = $resources->flatten()->pluck('environment_id')->filter()->unique()->map(strval(...))->all();
        $environmentClaims = $this->canonicalResourceClaims('environment', 'environment', 'project_environment', $canonicalEnvironmentIds, 'environment_id');
        $environmentIdentityClaims = $this->canonicalIdentityClaims('environment', 'project_environment', $canonicalEnvironmentIds);
        $canonicalEnvironments = ProjectEnvironment::query()->whereIn('id', $canonicalEnvironmentIds)
            ->get()->keyBy(fn (ProjectEnvironment $environment): string => (string) $environment->getKey());

        return $environments->filter(function (Environment $environment) use ($applicationProjectIds, $resources, $identities, $canonicalEnvironments, $environmentClaims, $environmentIdentityClaims): bool {
            $sourceId = (string) $environment->getKey();
            $resourceRows = $resources->get($sourceId, collect());
            $identityRows = $identities->get($sourceId, collect());
            if ($resourceRows->count() !== 1 || $identityRows->count() !== 1) {
                return false;
            }
            $resource = $resourceRows->sole();
            $identity = $identityRows->sole();
            $projectId = $applicationProjectIds[(string) $environment->application_id];
            $canonical = $canonicalEnvironments->get((string) $resource->environment_id);
            $canonicalClaims = $environmentClaims->get((string) ($resource->environment_id ?? ''), collect());
            $canonicalIdentityClaims = $environmentIdentityClaims->get((string) ($resource->environment_id ?? ''), collect());

            return in_array($resource->status, ['active', 'paused'], true) && $resource->environment_id !== null
                && (string) $resource->project_id === $projectId && $canonical !== null
                && in_array($canonical->status, ['active', 'paused'], true)
                && $canonicalClaims->count() === 1 && (string) $canonicalClaims->sole()->getKey() === (string) $resource->getKey()
                && $canonicalIdentityClaims->count() === 1 && (string) $canonicalIdentityClaims->sole()->source_id === $sourceId
                && (string) $canonical->project_id === $projectId
                && $identity->status === 'reconciled' && $identity->canonical_entity === 'project_environment'
                && (string) $identity->canonical_id === (string) $canonical->getKey();
        })->keyBy(fn (Environment $environment): string => (string) $environment->getKey());
    }

    /** @param list<string> $canonicalIds @return Collection<string, Collection<int, ProjectResource>> */
    private function canonicalResourceClaims(string $sourceEntity, string $resourceType, string $canonicalEntity, array $canonicalIds, string $canonicalColumn): Collection
    {
        if ($canonicalIds === []) {
            return collect();
        }

        $identityClaims = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', $sourceEntity)
            ->where('canonical_entity', $canonicalEntity)->whereIn('canonical_id', $canonicalIds)->get()->groupBy('canonical_id');
        $claimedSourceIds = $identityClaims->flatten()->pluck('source_id')->unique()->map(strval(...))->all();
        $resources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', $resourceType)
            ->where(function ($query) use ($canonicalColumn, $canonicalIds, $claimedSourceIds): void {
                $query->whereIn($canonicalColumn, $canonicalIds);
                if ($claimedSourceIds !== []) {
                    $query->orWhereIn('resource_id', $claimedSourceIds);
                }
            })->get();

        return collect($canonicalIds)->unique()->mapWithKeys(function (string $canonicalId) use ($canonicalColumn, $identityClaims, $resources): array {
            $sourceIds = $identityClaims->get($canonicalId, collect())->pluck('source_id')->map(strval(...))->all();
            $claims = $resources->filter(fn (ProjectResource $resource): bool => (string) $resource->{$canonicalColumn} === $canonicalId
                || in_array((string) $resource->resource_id, $sourceIds, true))->values();

            return [$canonicalId => $claims];
        });
    }

    /** @param list<string> $canonicalIds @return Collection<string, Collection<int, LegacyIdentityMap>> */
    private function canonicalIdentityClaims(string $sourceEntity, string $canonicalEntity, array $canonicalIds): Collection
    {
        if ($canonicalIds === []) {
            return collect();
        }

        return LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', $sourceEntity)
            ->where('canonical_entity', $canonicalEntity)->whereIn('canonical_id', $canonicalIds)
            ->get()->groupBy('canonical_id');
    }

    private function environmentLabel(Environment $environment): string
    {
        return (string) ($environment->application?->name ?? __('Application')).' / '.(string) $environment->name
            .($environment->status !== 'active' ? ' (paused)' : '');
    }

    /** @return array{workspace: Workspace, user: User} */
    private function requiredContext(PlatformUser $user, CoreWorkspace $workspace): array
    {
        $resolved = $this->context->resolve($user, $workspace);
        abort_if($resolved === null, 404);

        return $resolved;
    }

    private function assertCoreManager(PlatformUser $user, CoreWorkspace $workspace): void
    {
        abort_unless($this->workspaceAccess->canManageWorkspace($user, $workspace), 403);
    }

    private function canManage(PlatformUser $user, CoreWorkspace $workspace, User $actor, Workspace $sourceWorkspace): bool
    {
        return $this->workspaceAccess->canManageWorkspace($user, $workspace)
            && $actor->email_verified_at !== null
            && Gate::forUser($actor)->allows('update', $sourceWorkspace);
    }

    private function version(ServiceLevelObjective $objective): ?string
    {
        $version = $objective->getRawOriginal('updated_at');
        if (! is_string($version)
            || preg_match('/\\A\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?\\z/', $version) !== 1) {
            return null;
        }

        return $version;
    }
}
