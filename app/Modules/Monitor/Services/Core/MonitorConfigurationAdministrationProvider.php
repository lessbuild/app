<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorConfigurationAdministrationProvider;
use App\Core\Data\Monitor\MonitorConfigurationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\Search\WorkspaceSearchPattern;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeMonitor;
use App\Modules\Monitor\Services\SuspendHeartbeats;
use App\Modules\Monitor\Services\SuspendQueueMonitors;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorResult;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Paginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Core-rendered, mapped configuration using Monitor's existing authorization and mutation paths. */
final class MonitorConfigurationAdministrationProvider implements WorkspaceMonitorConfigurationAdministrationProvider
{
    private readonly MonitorAdministrationContext $context;

    public function __construct(
        LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        ProductWorkspaceAccess $productAccess,
        private readonly ChangeMonitor $changes,
        private readonly SuspendHeartbeats $heartbeats,
        private readonly SuspendQueueMonitors $queues,
    ) {
        $this->context = new MonitorAdministrationContext($identities, $workspaceAccess, $productAccess);
    }

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace, array $filters = []): ?MonitorConfigurationSnapshot
    {
        $this->context->resetReferences();
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return null;
        }
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $resolved;

        return new MonitorConfigurationSnapshot(
            applications: $this->applications($user, $workspace, $sourceWorkspace, $actor, $filters),
            environments: $this->environments($user, $workspace, $sourceWorkspace, $actor, $filters),
            checks: $this->checks($user, $workspace, $sourceWorkspace, $actor, $filters),
        );
    }

    public function updateApplication(PlatformUser $user, CoreWorkspace $workspace, string $applicationReference, array $data): MonitorMutationResult
    {
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'framework' => ['required', 'string', 'max:80'],
            'framework_version' => ['nullable', 'string', 'max:40'],
            'accent' => ['required', Rule::in(Application::ACCENTS)],
        ])->validate();
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($applicationReference, 'application', $sourceWorkspace);

        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $data, $actor, $user, $workspace): void {
            $application = Application::query()->whereBelongsTo($locked)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $application);
            abort_if($this->applicationBinding($application, $user, $workspace, $locked) === null, 404);
            $application->fill($data)->save();
            abort_if($this->applicationBinding($application->fresh(), $user, $workspace, $locked) === null, 404);
        });

        return new MonitorMutationResult(true, 'application_updated', $applicationReference);
    }

    public function updateEnvironment(PlatformUser $user, CoreWorkspace $workspace, string $environmentReference, array $data): MonitorMutationResult
    {
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'status' => ['required', Rule::in(['active', 'paused'])],
        ])->validate();
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($environmentReference, 'environment', $sourceWorkspace);

        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $data, $actor, $user, $workspace, $sourceWorkspace): void {
            $environmentRef = Environment::query()->whereKey($id)->firstOrFail();
            $application = Application::query()->whereBelongsTo($locked)->lockForUpdate()->findOrFail($environmentRef->application_id);
            $environment = Environment::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $environment);
            abort_if($this->environmentBinding($environment, $user, $workspace, $sourceWorkspace) === null, 404);

            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:120'],
                'slug' => [
                    'required', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                    Rule::unique(Environment::class, 'slug')->where('application_id', $application->getKey())->ignore($environment->getKey()),
                ],
                'status' => ['required', Rule::in(['active', 'paused'])],
            ])->validate();

            $lifecycleChanged = $environment->status !== $validated['status'];
            if ($lifecycleChanged && $validated['status'] !== 'active') {
                $this->heartbeats->environment((int) $environment->getKey());
                $this->queues->environment((int) $environment->getKey());
            }
            $environment->fill($validated)->save();
            if ($lifecycleChanged) {
                $application->increment('lifecycle_revision');
            }
            abort_if($this->environmentBinding($environment->fresh(), $user, $workspace, $sourceWorkspace) === null, 404);
        });

        return new MonitorMutationResult(true, 'environment_updated', $environmentReference);
    }

    public function updateMonitor(PlatformUser $user, CoreWorkspace $workspace, string $monitorReference, array $data): MonitorMutationResult
    {
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'enabled' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
            'interval_minutes' => ['sometimes', 'integer', Rule::in([1, 5, 15, 30, 60])],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,20'],
            'trigger_checks' => ['sometimes', 'integer', 'between:1,10'],
            'recovery_checks' => ['sometimes', 'integer', 'between:1,10'],
        ])->validate();
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($monitorReference, 'monitor', $sourceWorkspace);

        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $data, $actor, $user, $workspace, $sourceWorkspace): void {
            $monitor = Monitor::query()->forWorkspace($locked)->visibleTo($actor, $locked)
                ->with('environment.application')->whereKey($id)->firstOrFail();
            Gate::forUser($actor)->authorize('update', $monitor);
            $environment = $monitor->environment;
            abort_if($environment === null || $this->environmentBinding($environment, $user, $workspace, $sourceWorkspace) === null, 404);
            if (in_array($monitor->type, ['heartbeat', 'queue'], true)
                && array_intersect(['interval_minutes', 'timeout_seconds', 'trigger_checks', 'recovery_checks'], array_keys($data)) !== []) {
                throw ValidationException::withMessages(['interval_minutes' => __('This Monitor type uses its own schedule and cannot use an HTTP check cadence.')]);
            }

            $nativeData = $this->nativeMonitorData($monitor, $data, $sourceWorkspace);
            $this->changes->save($locked, $actor, $nativeData, $monitor);
            abort_if($this->environmentBinding($environment->fresh(), $user, $workspace, $sourceWorkspace) === null, 404);
        });

        return new MonitorMutationResult(true, 'check_updated', $monitorReference);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function applications(PlatformUser $user, CoreWorkspace $workspace, Workspace $sourceWorkspace, User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->resourceQuery($user, $workspace, 'application', ['active'])->whereNull('environment_id');
        $sourceQuery = Application::query()->whereBelongsTo($sourceWorkspace)->visibleTo($actor, $sourceWorkspace);

        return $this->mappedResourcePage($query, $sourceQuery, $filters['application_search'] ?? null,
            $filters['applications_page'] ?? 1, 'applications_page',
            function (ProjectResource $resource, Application $application) use ($user, $workspace, $sourceWorkspace, $actor): ?array {
                $binding = $this->applicationBinding($application, $user, $workspace, $sourceWorkspace);
                if ($binding === null || (string) $binding['resource']->getKey() !== (string) $resource->getKey()) {
                    return null;
                }

                return [
                    'reference' => $this->context->reference('application', $application->getKey(), $sourceWorkspace),
                    'project' => $binding['project']->name,
                    'name' => (string) $application->name,
                    'framework' => (string) $application->framework,
                    'framework_version' => (string) ($application->framework_version ?? ''),
                    'accent' => (string) ($application->accent ?? 'violet'),
                    'can_update' => Gate::forUser($actor)->allows('update', $application),
                ];
            });
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function environments(PlatformUser $user, CoreWorkspace $workspace, Workspace $sourceWorkspace, User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->resourceQuery($user, $workspace, 'environment', ['active', 'paused'])->whereNotNull('environment_id');
        $sourceQuery = Environment::query()->forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)->with('application');

        return $this->mappedResourcePage($query, $sourceQuery, $filters['environment_search'] ?? null,
            $filters['environments_page'] ?? 1, 'environments_page',
            function (ProjectResource $resource, Environment $environment) use ($user, $workspace, $sourceWorkspace, $actor): ?array {
                $binding = $this->environmentBinding($environment, $user, $workspace, $sourceWorkspace);
                if ($binding === null || (string) $binding['resource']->getKey() !== (string) $resource->getKey()) {
                    return null;
                }

                return [
                    'reference' => $this->context->reference('environment', $environment->getKey(), $sourceWorkspace),
                    'project' => $binding['project']->name,
                    'application' => (string) $environment->application->name,
                    'name' => (string) $environment->name,
                    'slug' => (string) $environment->slug,
                    'status' => (string) $environment->status,
                    'can_update' => Gate::forUser($actor)->allows('update', $environment),
                ];
            });
    }

    /** @param Builder<ProjectResource> $query @param Builder<Application>|Builder<Environment> $sourceQuery @param callable(ProjectResource, Application|Environment): (array<string, mixed>|null) $present @return LengthAwarePaginator<int, array<string, mixed>> */
    private function mappedResourcePage(Builder $query, Builder $sourceQuery, mixed $search, mixed $requestedPage, string $pageName, callable $present): LengthAwarePaginator
    {
        $perPage = 20;
        $page = max(1, (int) $requestedPage);
        $offset = ($page - 1) * $perPage;
        $total = 0;
        $items = [];

        // Exact native/Core bindings can only be verified across databases in code.
        // Stream bounded Core batches and fetch source matches by those IDs so stale
        // rows and search results are filtered before counts and page selection.
        $query->chunkById(100, function ($resources) use (
            &$items, &$total, $offset, $perPage, $sourceQuery, $search, $present,
        ): void {
            $sourceIds = $resources->pluck('resource_id')->map(strval(...))->all();
            $sources = $this->filterSourceSearch((clone $sourceQuery)->whereIn('id', $sourceIds), $search)
                ->get()->keyBy(fn ($source): string => (string) $source->getKey());

            foreach ($resources as $resource) {
                $source = $sources->get((string) $resource->resource_id);
                if ($source === null) {
                    continue;
                }
                $item = $present($resource, $source);
                if ($item === null) {
                    continue;
                }
                if ($total >= $offset && count($items) < $perPage) {
                    $items[] = $item;
                }
                $total++;
            }
        });

        return new LengthAwarePaginatorResult(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query(), 'pageName' => $pageName],
        );
    }

    /** @param Builder<Application>|Builder<Environment> $sourceQuery @return Builder<Application>|Builder<Environment> */
    private function filterSourceSearch(Builder $sourceQuery, mixed $search): Builder
    {
        if (! filled($search)) {
            return $sourceQuery;
        }

        $pattern = WorkspaceSearchPattern::contains(trim((string) $search));

        return $sourceQuery->where(function (Builder $source) use ($pattern): void {
            $source->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
            if ($source->getModel() instanceof Application) {
                $source->orWhereRaw("framework LIKE ? ESCAPE '!'", [$pattern]);
            } else {
                $source->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('application', fn (Builder $application) => $application->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            }
        });
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function checks(PlatformUser $user, CoreWorkspace $workspace, Workspace $sourceWorkspace, User $actor, array $filters): LengthAwarePaginator
    {
        $query = Monitor::query()->forWorkspace($sourceWorkspace)->visibleTo($actor, $sourceWorkspace)
            ->with('environment.application');
        if (filled($filters['check_search'] ?? null)) {
            $pattern = WorkspaceSearchPattern::contains(trim((string) $filters['check_search']));
            $query->where(function (Builder $checks) use ($pattern): void {
                $checks->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('environment', fn (Builder $environment) => $environment->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('application', fn (Builder $application) => $application->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])));
            });
        }

        $perPage = 20;
        $page = max(1, (int) ($filters['checks_page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $total = 0;
        $items = [];
        $environmentBindings = [];
        $environmentBindingOrder = [];

        // Monitor and Core may use separate databases, so exact cross-product mapping
        // checks happen while streaming native checks in bounded ID-ordered batches.
        // Count only authorized mapped checks before selecting the requested page.
        $query->orderBy('id')->chunkById(100, function ($checks) use (
            &$items, &$total, &$environmentBindings, &$environmentBindingOrder, $offset, $perPage, $user, $workspace, $sourceWorkspace, $actor,
        ): void {
            foreach ($checks as $monitor) {
                $environment = $monitor->environment;
                $binding = null;
                if ($environment !== null) {
                    $environmentId = (string) $environment->getKey();
                    if (! array_key_exists($environmentId, $environmentBindings)) {
                        if (count($environmentBindingOrder) >= 256) {
                            unset($environmentBindings[array_shift($environmentBindingOrder)]);
                        }
                        $environmentBindings[$environmentId] = $this->environmentBinding($environment, $user, $workspace, $sourceWorkspace);
                        $environmentBindingOrder[] = $environmentId;
                    }
                    $binding = $environmentBindings[$environmentId];
                }
                if ($environment === null || $binding === null) {
                    continue;
                }

                if ($total >= $offset && count($items) < $perPage) {
                    $items[] = [
                        'reference' => $this->context->reference('monitor', $monitor->getKey(), $sourceWorkspace),
                        'project' => $binding['project']->name,
                        'application' => (string) $environment->application->name,
                        'environment' => (string) $environment->name,
                        'name' => (string) $monitor->name,
                        'type' => (string) $monitor->typeLabel(),
                        'type_key' => (string) $monitor->type,
                        'enabled' => (bool) $monitor->enabled,
                        'health' => (string) $monitor->healthLabel(),
                        'version' => (int) $monitor->state_version,
                        'interval_minutes' => (int) $monitor->interval_minutes,
                        'timeout_seconds' => (int) $monitor->timeout_seconds,
                        'trigger_checks' => (int) $monitor->trigger_checks,
                        'recovery_checks' => (int) $monitor->recovery_checks,
                        'can_update' => Gate::forUser($actor)->allows('update', $monitor),
                    ];
                }
                $total++;
            }
        });

        return new LengthAwarePaginatorResult(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query(), 'pageName' => 'checks_page'],
        );
    }

    /** @param list<string> $statuses @return Builder<ProjectResource> */
    private function resourceQuery(PlatformUser $user, CoreWorkspace $workspace, string $type, array $statuses): Builder
    {
        $accessibleProjects = $this->workspaceAccess->accessibleProductProjects($user, $workspace, 'monitor');

        return ProjectResource::query()->where('product', 'monitor')->where('resource_type', $type)->whereIn('status', $statuses)
            ->whereIn('project_id', $accessibleProjects->select('id'));
    }

    /** @return array{resource: ProjectResource, project: Project}|null */
    private function applicationBinding(Application $application, PlatformUser $user, CoreWorkspace $workspace, Workspace $sourceWorkspace): ?array
    {
        if ((string) $application->workspace_id !== (string) $sourceWorkspace->getKey() || $application->trashed()) {
            return null;
        }
        $resources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'application')
            ->where('resource_id', (string) $application->getKey())->get();
        $identities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'application')
            ->where('source_id', (string) $application->getKey())->get();
        if ($resources->count() !== 1 || $identities->count() !== 1) {
            return null;
        }
        $resource = $resources->sole();
        $identity = $identities->sole();
        $project = $this->activeProject($user, $workspace, (string) $resource->project_id);
        if ($resource->status !== 'active' || $resource->environment_id !== null || $project === null
            || $identity->status !== 'reconciled' || $identity->canonical_entity !== 'project'
            || (string) $identity->canonical_id !== (string) $project->getKey()) {
            return null;
        }

        return ['resource' => $resource, 'project' => $project];
    }

    /** @return array{resource: ProjectResource, project: Project}|null */
    private function environmentBinding(Environment $environment, PlatformUser $user, CoreWorkspace $workspace, Workspace $sourceWorkspace): ?array
    {
        $application = $environment->application;
        if ($application === null || $environment->trashed()) {
            return null;
        }
        $applicationBinding = $this->applicationBinding($application, $user, $workspace, $sourceWorkspace);
        if ($applicationBinding === null) {
            return null;
        }
        $resources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->where('resource_id', (string) $environment->getKey())->get();
        $identities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'environment')
            ->where('source_id', (string) $environment->getKey())->get();
        if ($resources->count() !== 1 || $identities->count() !== 1) {
            return null;
        }
        $resource = $resources->sole();
        $identity = $identities->sole();
        $projectId = (string) $applicationBinding['project']->getKey();
        $canonicalEnvironment = $resource->environment_id === null ? null : ProjectEnvironment::query()
            ->whereKey($resource->environment_id)->where('project_id', $projectId)->whereIn('status', ['active', 'paused'])->first();
        if (! in_array($resource->status, ['active', 'paused'], true) || (string) $resource->project_id !== $projectId
            || $canonicalEnvironment === null || $identity->status !== 'reconciled'
            || $identity->canonical_entity !== 'project_environment'
            || (string) $identity->canonical_id !== (string) $canonicalEnvironment->getKey()) {
            return null;
        }

        return ['resource' => $resource, 'project' => $applicationBinding['project']];
    }

    private function activeProject(PlatformUser $user, CoreWorkspace $workspace, string $projectId): ?Project
    {
        $project = Project::query()->whereKey($projectId)->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')->whereNull('archived_at')->first();
        if ($project === null) {
            return null;
        }
        $products = ProjectProduct::query()->where('project_id', $project->getKey())->where('product', 'monitor')->get();

        return $products->count() === 1 && $products->sole()->status === 'active'
            && $this->workspaceAccess->canAccessProductResource($user, $project, 'monitor') ? $project : null;
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function nativeMonitorData(Monitor $monitor, array $changes, Workspace $workspace): array
    {
        $destinations = $monitor->destinations()->get();
        $workspaceDestinations = $monitor->destinations()->where('workspace_id', $workspace->getKey())->get();
        abort_unless($destinations->count() === $workspaceDestinations->count(), 404);
        $routeStates = $workspaceDestinations->map(fn ($destination): string => (int) $destination->pivot->opened.':'.(int) $destination->pivot->recovered)->unique();
        if ($routeStates->count() > 1) {
            throw ValidationException::withMessages(['destinations' => __('Edit mixed per-destination routing in Monitor before changing this check in Core.')]);
        }
        [$opened, $recovered] = array_map('intval', explode(':', (string) ($routeStates->first() ?? '0:0')));
        $data = [
            'name' => (string) $changes['name'], 'enabled' => filter_var($changes['enabled'], FILTER_VALIDATE_BOOL),
            'environment_id' => (int) $monitor->environment_id, 'check_type' => (string) $monitor->type,
            'timeout_seconds' => (int) ($changes['timeout_seconds'] ?? $monitor->timeout_seconds),
            'interval_minutes' => (int) ($changes['interval_minutes'] ?? $monitor->interval_minutes),
            'trigger_checks' => (int) ($changes['trigger_checks'] ?? $monitor->trigger_checks),
            'recovery_checks' => (int) ($changes['recovery_checks'] ?? $monitor->recovery_checks),
            'version' => (int) $changes['version'],
            'destinations' => $workspaceDestinations->modelKeys(), 'opened' => (bool) $opened, 'recovered' => (bool) $recovered,
        ];

        if ($monitor->type === 'http') {
            $data += [
                'method' => (string) $monitor->method,
                'status_min' => (int) $monitor->status_min,
                'status_max' => (int) $monitor->status_max,
                'max_duration_ms' => $monitor->max_duration_ms === null ? null : (int) $monitor->max_duration_ms,
            ];
        } elseif ($monitor->type === 'dns') {
            $data += ['dns_record_type' => (string) $monitor->dns_record_type, 'dns_match' => (string) $monitor->dns_match];
        } elseif ($monitor->type === 'heartbeat') {
            $data += [
                'heartbeat_schedule' => (string) $monitor->heartbeat_schedule,
                'heartbeat_interval_minutes' => $monitor->heartbeat_interval_minutes,
                'heartbeat_cron' => $monitor->heartbeat_cron,
                'heartbeat_timezone' => (string) $monitor->heartbeat_timezone,
                'heartbeat_grace_minutes' => (int) $monitor->heartbeat_grace_minutes,
            ];
        } elseif ($monitor->type === 'queue') {
            $data += [
                'queue_name' => (string) $monitor->queue_name,
                'queue_settings' => QueueMonitorSettings::normalize($monitor->queue_settings),
            ];
        }

        return $data;
    }

    /** @return array{workspace: Workspace, user: User} */
    private function requiredContext(PlatformUser $user, CoreWorkspace $workspace): array
    {
        $resolved = $this->context->resolve($user, $workspace);
        abort_if($resolved === null, 404);

        return $resolved;
    }
}
