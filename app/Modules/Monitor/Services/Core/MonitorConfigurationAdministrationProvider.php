<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorConfigurationAdministrationProvider;
use App\Core\Data\Monitor\MonitorConfigurationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
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
use App\Modules\Monitor\Services\PublicHttpTarget;
use App\Modules\Monitor\Services\SuspendHeartbeats;
use App\Modules\Monitor\Services\SuspendQueueMonitors;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Paginator;
use Illuminate\Support\Facades\Route;
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
        private readonly PublicHttpTarget $httpTargets,
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

    /** Archive one exactly mapped check through Monitor's native archive, which closes its incident and revokes heartbeat/queue keys. */
    public function archiveMonitor(PlatformUser $user, CoreWorkspace $workspace, string $monitorReference, int $version): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($monitorReference, 'monitor', $sourceWorkspace);

        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($id, $version, $actor, $user, $workspace, $sourceWorkspace): void {
            $monitor = Monitor::query()->forWorkspace($locked)->visibleTo($actor, $locked)
                ->with('environment.application')->whereKey($id)->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $monitor);
            $environment = $monitor->environment;
            abort_if($environment === null || $this->environmentBinding($environment, $user, $workspace, $sourceWorkspace) === null, 404);

            $this->changes->archive($monitor, $locked, $actor, $version);
        });

        return new MonitorMutationResult(true, 'check_archived', $monitorReference);
    }

    public function createHttpCheck(PlatformUser $user, CoreWorkspace $workspace, string $environmentReference, array $data): MonitorMutationResult
    {
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'request_url' => ['required', 'string', 'max:2048'],
            'interval_minutes' => ['required', 'integer', Rule::in([1, 5, 15, 30, 60])],
            'timeout_seconds' => ['required', 'integer', 'between:1,20'],
        ])->validate();
        $target = $this->httpTargets->parse($data['request_url']);
        $parts = parse_url($data['request_url']);
        if ($target === null || ! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages([
                'request_url' => __('Use a public HTTP or HTTPS health URL without credentials, query strings, or fragments.'),
            ]);
        }

        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($environmentReference, 'environment', $sourceWorkspace);
        $nativeEnvironment = Environment::query()->whereKey($id)->firstOrFail();
        $applicationId = (string) $nativeEnvironment->application_id;
        $environmentResources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->where('resource_id', $id)->get();
        abort_unless($environmentResources->count() === 1, 404);
        $projectId = (string) $environmentResources->sole()->project_id;
        $created = null;

        // Begin Core without locking it, then let Monitor acquire its native locks first. Core's
        // current authority/mapping reads run inside that source transaction and remain locked
        // through its commit. SQLite needs a writer reservation before those reads because it
        // ignores FOR UPDATE and cannot safely promote a stale WAL snapshot.
        // Do not retry this outer callback: the two product databases do not share an atomic commit.
        DB::connection('core')->transaction(function () use (
            $user, $workspace, $sourceWorkspace, $actor, $id, $applicationId, $projectId, $data, &$created,
        ): void {
            $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use (
                $id, $applicationId, $projectId, $data, $actor, $user, $workspace, $sourceWorkspace, &$created,
            ): void {
                $environmentRef = Environment::query()->whereKey($id)->firstOrFail();
                $application = Application::query()->whereBelongsTo($locked)->lockForUpdate()->findOrFail($environmentRef->application_id);
                $environment = Environment::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($id);
                abort_unless((string) $environment->application_id === $applicationId, 404);
                Gate::forUser($actor)->authorize('create', [Monitor::class, $locked]);
                Gate::forUser($actor)->authorize('update', $environment);
                abort_unless($environment->status === 'active', 409, 'Resume this environment before creating an enabled HTTP check.');

                $this->reserveCoreHttpCheckWriter($user);
                $this->lockCoreHttpCheckAuthority($user, $workspace, $sourceWorkspace, $actor, $applicationId, $id, $projectId);

                $created = $this->changes->save($locked, $actor, [
                    'check_type' => 'http',
                    'environment_id' => (int) $environment->getKey(),
                    'name' => trim($data['name']),
                    'request_url' => $data['request_url'],
                    'method' => 'GET',
                    'status_min' => 200,
                    'status_max' => 299,
                    'timeout_seconds' => (int) $data['timeout_seconds'],
                    'interval_minutes' => (int) $data['interval_minutes'],
                    'trigger_checks' => 2,
                    'recovery_checks' => 2,
                    'enabled' => true,
                    'destinations' => [],
                    'opened' => true,
                    'recovered' => true,
                ]);
            });
        });

        abort_unless($created instanceof Monitor, 500);

        return new MonitorMutationResult(true, 'check_created', $this->context->reference('monitor', $created->getKey(), $sourceWorkspace));
    }

    private function reserveCoreHttpCheckWriter(PlatformUser $user): void
    {
        if (DB::connection('core')->getDriverName() !== 'sqlite') {
            return;
        }

        $reserved = DB::connection('core')->table('users')->where('id', $user->getKey())->update(['id' => DB::raw('id')]);
        abort_unless($reserved === 1, 404);
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
                    // Archive stays Monitor-owned so Core's restoration provenance is not bypassed.
                    'archive_url' => Gate::forUser($actor)->allows('delete', $application) && Route::has('monitor.applications.show')
                        ? route('monitor.applications.show', $application->getKey())
                        : null,
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
                    'archive_url' => Gate::forUser($actor)->allows('delete', $environment) && Route::has('monitor.environments.show')
                        ? route('monitor.environments.show', [$environment->application_id, $environment->getKey()])
                        : null,
                    'can_create_check' => $environment->status === 'active'
                        && $binding['resource']->status === 'active'
                        && $binding['canonical_environment']->status === 'active'
                        && $actor->hasVerifiedEmail()
                        && Gate::forUser($actor)->allows('update', $environment)
                        && Gate::forUser($actor)->allows('create', [Monitor::class, $sourceWorkspace]),
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
                        'can_archive' => $actor->hasVerifiedEmail() && Gate::forUser($actor)->allows('delete', $monitor),
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

    /** @return array{resource: ProjectResource, project: Project, canonical_environment: ProjectEnvironment}|null */
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

        return [
            'resource' => $resource,
            'project' => $applicationBinding['project'],
            'canonical_environment' => $canonicalEnvironment,
        ];
    }

    private function lockCoreHttpCheckAuthority(
        PlatformUser $user,
        CoreWorkspace $workspace,
        Workspace $sourceWorkspace,
        User $actor,
        string $applicationId,
        string $environmentId,
        string $projectId,
    ): void {
        $lockedUser = PlatformUser::query()->whereKey($user->getKey())->lockForUpdate()->first();
        abort_unless($lockedUser !== null && $lockedUser->status === 'active', 404);

        $lockedWorkspace = CoreWorkspace::query()->whereKey($workspace->getKey())->lockForUpdate()->first();
        abort_unless($lockedWorkspace !== null && $lockedWorkspace->status === 'active' && $lockedWorkspace->archived_at === null, 404);

        $memberships = WorkspaceMembership::query()->where('workspace_id', $lockedWorkspace->getKey())
            ->where('user_id', $lockedUser->getKey())->orderBy('id')->lockForUpdate()->get();
        abort_unless($memberships->count() === 1, 404);
        $membership = $memberships->sole();
        abort_unless($membership->status === 'active' && $membership->revoked_at === null
            && ($membership->expires_at === null || $membership->expires_at->isFuture()), 404);

        $grants = WorkspaceProductAccess::query()->where('membership_id', $membership->getKey())
            ->where('product', 'monitor')->orderBy('id')->lockForUpdate()->get();
        abort_unless($grants->count() === 1, 404);
        $grant = $grants->sole();
        abort_unless($grant->status === 'active' && $grant->revoked_at === null
            && ($grant->expires_at === null || $grant->expires_at->isFuture()), 404);

        $projectMemberships = ProjectMembership::query()->where('project_id', $projectId)
            ->where('user_id', $lockedUser->getKey())->orderBy('id')->lockForUpdate()->get();
        abort_unless($projectMemberships->count() === 1, 404);
        $projectMembership = $projectMemberships->sole();
        abort_unless($projectMembership->status === 'active' && $projectMembership->revoked_at === null, 404);

        $products = ProjectProduct::query()->where('project_id', $projectId)->where('product', 'monitor')
            ->orderBy('id')->lockForUpdate()->get();
        abort_unless($products->count() === 1 && $products->sole()->status === 'active', 404);

        $project = Project::query()->whereKey($projectId)->where('workspace_id', $lockedWorkspace->getKey())
            ->lockForUpdate()->first();
        abort_unless($project !== null && $project->status === 'active' && $project->archived_at === null, 404);

        $environmentResources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->where('resource_id', $environmentId)->orderBy('id')->lockForUpdate()->get();
        abort_unless($environmentResources->count() === 1, 404);
        $environmentResource = $environmentResources->sole();
        abort_unless((string) $environmentResource->project_id === (string) $project->getKey()
            && $environmentResource->environment_id !== null && $environmentResource->status === 'active', 404);

        $environmentIdentities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'environment')
            ->where('source_id', $environmentId)->orderBy('id')->lockForUpdate()->get();
        abort_unless($environmentIdentities->count() === 1, 404);
        $environmentIdentity = $environmentIdentities->sole();
        abort_unless($environmentIdentity->status === 'reconciled'
            && $environmentIdentity->canonical_entity === 'project_environment', 404);

        $canonicalEnvironment = ProjectEnvironment::query()->whereKey($environmentIdentity->canonical_id)
            ->where('project_id', $project->getKey())->lockForUpdate()->first();
        abort_unless($canonicalEnvironment !== null && $canonicalEnvironment->status === 'active'
            && (string) $environmentResource->environment_id === (string) $canonicalEnvironment->getKey(), 404);

        $workspaceIdentities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'workspace')
            ->where(function (Builder $query) use ($workspace, $sourceWorkspace): void {
                $query->where('source_id', (string) $sourceWorkspace->getKey())
                    ->orWhere(fn (Builder $canonical): Builder => $canonical->where('canonical_entity', 'workspace')
                        ->where('canonical_id', (string) $workspace->getKey()));
            })->orderBy('source_id')->orderBy('id')->lockForUpdate()->get();
        $sourceWorkspaceIds = $workspaceIdentities->where('canonical_entity', 'workspace')
            ->where('canonical_id', (string) $workspace->getKey())->pluck('source_id')->map(strval(...))->all();
        $sourceWorkspaceIdentity = $workspaceIdentities->where('source_id', (string) $sourceWorkspace->getKey())->values();
        abort_unless($sourceWorkspaceIds === [(string) $sourceWorkspace->getKey()] && $sourceWorkspaceIdentity->count() === 1, 404);
        $workspaceIdentity = $sourceWorkspaceIdentity->sole();
        abort_unless($workspaceIdentity->status === 'reconciled' && $workspaceIdentity->canonical_entity === 'workspace'
            && (string) $workspaceIdentity->canonical_id === (string) $workspace->getKey(), 404);

        $userIdentities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'user')
            ->where(function (Builder $query) use ($user, $actor): void {
                $query->where('source_id', (string) $actor->getKey())
                    ->orWhere(fn (Builder $canonical): Builder => $canonical->where('canonical_entity', 'user')
                        ->where('canonical_id', (string) $user->getKey()));
            })->orderBy('source_id')->orderBy('id')->lockForUpdate()->get();
        $sourceUserIds = $userIdentities->where('canonical_entity', 'user')->where('canonical_id', (string) $user->getKey())
            ->pluck('source_id')->map(strval(...))->all();
        $sourceUserIdentity = $userIdentities->where('source_id', (string) $actor->getKey())->values();
        abort_unless($sourceUserIds === [(string) $actor->getKey()] && $sourceUserIdentity->count() === 1, 404);
        $userIdentity = $sourceUserIdentity->sole();
        abort_unless($userIdentity->status === 'reconciled' && $userIdentity->canonical_entity === 'user'
            && (string) $userIdentity->canonical_id === (string) $user->getKey(), 404);

        $applicationResources = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'application')
            ->where('resource_id', $applicationId)->orderBy('id')->lockForUpdate()->get();
        abort_unless($applicationResources->count() === 1, 404);
        $applicationResource = $applicationResources->sole();
        abort_unless((string) $applicationResource->project_id === (string) $project->getKey()
            && $applicationResource->environment_id === null && $applicationResource->status === 'active', 404);

        $applicationIdentities = LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'application')
            ->where('source_id', $applicationId)->orderBy('id')->lockForUpdate()->get();
        abort_unless($applicationIdentities->count() === 1, 404);
        $applicationIdentity = $applicationIdentities->sole();
        abort_unless($applicationIdentity->status === 'reconciled' && $applicationIdentity->canonical_entity === 'project'
            && (string) $applicationIdentity->canonical_id === (string) $project->getKey(), 404);
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
