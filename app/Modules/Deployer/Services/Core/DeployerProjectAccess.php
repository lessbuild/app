<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\ScheduledTask;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Explicit interactive-query restrictions; unattended product work keeps its own authority. */
final class DeployerProjectAccess
{
    public function __construct(private readonly MappedProjectResourceAccess $access) {}

    public function workspace(User $user): bool
    {
        return ! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || $this->access->deniedResourceIds($user, 'deployer', 'project', 'organization', (string) $user->current_organization_id, []) !== null;
    }

    public function project(User $user, Project $project): bool
    {
        return ! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || $this->projects(Project::query()->whereKey($project->getKey()), $user)->exists();
    }

    public function environment(User $user, Environment $environment): bool
    {
        return ! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || $this->environments(Environment::query()->whereKey($environment->getKey()), $user)->exists();
    }

    public function website(User $user, Website $website): bool
    {
        return ! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || $this->websites(Website::query()->whereKey($website->getKey()), $user)->exists();
    }

    public function server(User $user, Server $server): bool
    {
        return ! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || $this->servers(Server::query()->whereKey($server->getKey()), $user)->exists();
    }

    public function repository(User $user, Repository $repository): bool
    {
        return ! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || $this->repositories(Repository::query()->whereKey($repository->getKey()), $user)->exists();
    }

    public function build(User $user, Build $build): bool
    {
        return ! app(ProductAuthentication::class)->usesCoreAuthority('deployer')
            || $this->builds(Build::query()->whereKey($build->getKey()), $user)->exists();
    }

    public function projects(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        $this->resource($query, $user, 'project', 'projects.id', $purpose);
        $deniedEnvironments = $this->denied($user, 'environment', $purpose);

        if ($deniedEnvironments === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($deniedEnvironments !== []) {
            $query->whereDoesntHave('environments', fn (Builder $environment) => $environment->whereIn('environments.id', $deniedEnvironments));
        }

        return $query;
    }

    public function environments(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        $this->resource($query, $user, 'environment', 'environments.id', $purpose);
        $deniedProjects = $this->denied($user, 'project', $purpose);

        return $deniedProjects === null
            ? $query->whereRaw('1 = 0')
            : $query->whereNotIn('environments.project_id', $deniedProjects);
    }

    public function websites(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        $denials = [];

        return $this->dependencies($query, $user, 'website', $purpose, $denials);
    }

    public function servers(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        $denials = [];

        return $this->dependencies($query, $user, 'server', $purpose, $denials);
    }

    public function repositories(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        $denials = [];

        return $this->dependencies($query, $user, 'repository', $purpose, $denials);
    }

    public function builds(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        $denials = [];

        return $this->dependencies($query, $user, 'build', $purpose, $denials);
    }

    /** Shared server operations affect every attached site, including retained sites and their repositories. */
    public function canChangeServer(User $user, Server $server): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }
        $denials = [];

        return $this->mutableServers(Server::query()->whereKey($server->getKey()), $user, $denials)->exists();
    }

    /** Site mutations affect its deployed repositories; viewing the site does not require every child. */
    public function canChangeWebsite(User $user, Website $website): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }
        $denials = [];

        return $this->mutableWebsites(Website::withTrashed()->whereKey($website->getKey()), $user, $denials)->exists();
    }

    /** Environment operations may change both direct placements independently of its canonical project. */
    public function canChangeEnvironment(User $user, Environment $environment): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }
        $denials = [];

        return $this->mutableEnvironments(Environment::query()->whereKey($environment->getKey()), $user, $denials)->exists();
    }

    /** Build operations can target a placement different from the repository's usual site. */
    public function canChangeBuild(User $user, Build $build): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }
        $current = Build::query()->find($build->getKey());
        if ($current === null || ! $this->build($user, $current)) {
            return false;
        }
        $repository = $current->repository_id === null ? null : Repository::withTrashed()->find($current->repository_id);
        if ($current->repository_id !== null && ($repository === null || ! $this->canChangeRepository($user, $repository))) {
            return false;
        }
        $environment = $current->environment_id === null ? null : Environment::query()->find($current->environment_id);

        return $current->environment_id === null || ($environment !== null && $this->canChangeEnvironment($user, $environment));
    }

    /** Deployment changes share a site; deleting a repository also hides its retained build history. */
    public function canChangeRepository(User $user, Repository $repository, bool $deleting = false): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }
        $denials = [];
        $query = $this->dependencies(Repository::withTrashed()->whereKey($repository->getKey()), $user, 'repository', ProjectResourceAccessPurpose::Interactive, $denials);
        $websites = $this->mutableWebsites($this->owned(Website::withTrashed(), $user), $user, $denials);
        $this->requiresResource($query, 'repositories.website_id', $websites->select('websites.id'));

        if ($deleting) {
            $builds = $this->dependencies(Build::query(), $user, 'build', ProjectResourceAccessPurpose::Interactive, $denials);
            $query->whereDoesntHave('builds', fn (Builder $build) => $build->whereNotIn('builds.id', $builds->select('builds.id')));
        }

        return $query->exists();
    }

    /** Provider connection metadata is workspace-scoped; attached resource payloads are filtered separately. */
    public function providers(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        return $this->resource($query, $user, 'provider', 'providers.id', $purpose);
    }

    /** Provider credential mutations can affect every attached project. */
    public function canChangeProvider(User $user, Provider $provider): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }

        $denials = [];
        $servers = $this->mutableServers($this->owned(Server::query(), $user), $user, $denials)->select('servers.id');
        $websites = $this->mutableWebsites($this->owned(Website::withTrashed(), $user), $user, $denials)->select('websites.id');
        $repositories = $this->dependencies($this->owned(Repository::withTrashed(), $user), $user, 'repository', ProjectResourceAccessPurpose::Interactive, $denials);
        $this->requiresResource($repositories, 'repositories.website_id', clone $websites);

        return $this->resource(Provider::query()->whereKey($provider->getKey()), $user, 'provider', 'providers.id', ProjectResourceAccessPurpose::Interactive, $denials)
            ->whereDoesntHave('servers', fn (Builder $server) => $server->whereNotIn('servers.id', $servers))
            ->whereDoesntHave('repositories', fn (Builder $repository) => $repository->withTrashed()->whereNotIn('repositories.id', $repositories->select('repositories.id')))
            ->whereDoesntHave('domains', fn (Builder $domain) => $domain->whereNotIn('website_id', $websites))
            ->exists();
    }

    /** All page components are required for its private management and subscriber information. */
    public function statusPages(Builder|Relation $query, User $user, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): Builder|Relation
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return $query;
        }

        $this->resource($query, $user, 'status_page', 'status_pages.id', $purpose);

        return $query->whereDoesntHave('websites', fn (Builder $website) => $website->withTrashed()->whereNotIn(
            'websites.id', $this->websites(Website::withTrashed()->where('organization_id', $user->current_organization_id), $user, $purpose)->select('websites.id'),
        ));
    }

    /** Read projections require access to the environment, front server, and every application node. */
    public function loadBalancers(Builder|Relation $query, User $user): Builder|Relation
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return $query;
        }

        $this->resource($query, $user, 'load_balancer', 'load_balancers.id');
        $servers = $this->servers(Server::query()->where('organization_id', $user->current_organization_id), $user)->select('servers.id');
        $environments = $this->environments(Environment::query()->whereHas(
            'project', fn (Builder $project) => $project->where('organization_id', $user->current_organization_id),
        ), $user)->select('environments.id');

        return $query->whereIn('environment_id', $environments)
            ->whereIn('server_id', clone $servers)
            ->whereDoesntHave('nodes', fn (Builder $node) => $node->whereNotIn('server_id', clone $servers));
    }

    /** Remote balancer operations also require mutation authority over shared placements and node sites. */
    public function canChangeLoadBalancer(User $user, LoadBalancer $loadBalancer): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }
        $denials = [];
        $servers = $this->mutableServers(Server::query()->where('organization_id', $user->current_organization_id), $user, $denials)->select('servers.id');
        $environments = $this->mutableEnvironments(Environment::query()->whereHas(
            'project', fn (Builder $project) => $project->where('organization_id', $user->current_organization_id),
        ), $user, $denials)->select('environments.id');

        return $this->loadBalancers(LoadBalancer::query()->whereKey($loadBalancer->getKey()), $user)
            ->whereIn('environment_id', $environments)
            ->whereIn('server_id', clone $servers)
            ->whereDoesntHave('nodes', fn (Builder $node) => $node->whereNull('server_id')->orWhereNotIn('server_id', clone $servers))
            ->exists();
    }

    /** Destination credentials affect retained snapshots and every attached schedule. */
    public function canChangeBackupDestination(User $user, BackupDestination $destination): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }

        $denials = [];
        $websites = $this->mutableWebsites(Website::withTrashed()->where('organization_id', $user->current_organization_id), $user, $denials)->select('websites.id');

        return $this->workspace($user)
            && ! $destination->schedules()->whereNotIn('website_id', clone $websites)->exists()
            && ! $destination->backups()->whereNotIn('website_id', clone $websites)->exists();
    }

    /** Workspace-wide settings and unattributed historical payloads require every project resource. */
    public function canAccessWorkspaceResources(User $user): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return true;
        }

        if ($user->currentOrganization === null || ! $this->workspace($user)) {
            return false;
        }

        foreach (['projects', 'servers', 'websites', 'repositories'] as $relation) {
            $owned = $user->currentOrganization->{$relation}();
            $column = $owned->getModel()->qualifyColumn('id');
            $allowed = $this->{$relation}(clone $owned, $user);
            if ($owned->whereNotIn($column, $allowed->select($column))->exists()) {
                return false;
            }
        }

        return true;
    }

    /** Scope operational incident categories before pagination, totals, or export. */
    public function incidents(Builder|Relation $query, User $user): Builder|Relation
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return $query;
        }

        $allResources = $this->canAccessWorkspaceResources($user);
        $sources = [
            'deployment' => $this->builds(Build::query()->where(fn (Builder $build) => $build->whereHas('repository', fn (Builder $repository) => $repository->where('organization_id', $user->current_organization_id))->orWhereHas('environment.project', fn (Builder $project) => $project->where('organization_id', $user->current_organization_id))), $user)->select('builds.id'),
            'website' => $this->websites(Website::query()->where('organization_id', $user->current_organization_id), $user)->select('websites.id'),
            'server' => $this->servers(Server::query()->where('organization_id', $user->current_organization_id), $user)->select('servers.id'),
            'provider' => $this->providers(Provider::query()->where('organization_id', $user->current_organization_id), $user)->select('providers.id'),
            'metric' => MetricAlertRule::query()->where('organization_id', $user->current_organization_id)->where(fn (Builder $rule) => $rule
                ->whereIn('server_id', $this->servers(Server::query()->where('organization_id', $user->current_organization_id), $user)->select('servers.id'))
                ->when($allResources, fn (Builder $global) => $global->orWhereNull('server_id')))->select('id'),
            'scheduled_task' => ScheduledTask::query()->whereIn('environment_id', $this->environments(Environment::query()->whereHas('project', fn (Builder $project) => $project->where('organization_id', $user->current_organization_id)), $user)->select('environments.id'))->select('id'),
        ];
        $query->whereIn('category', array_keys($sources));
        foreach ($sources as $category => $source) {
            $query->where(fn (Builder $incident) => $incident->where('category', '!=', $category)
                ->orWhereIn('resource_id', $source));
        }

        return $query;
    }

    /**
     * Fixed dependency order: provider -> server -> website -> repository -> build.
     * Only the target uses the caller's query. Dependency candidates retain native tenant ownership,
     * including personal resources and retained parents. Raw mapped decisions are memoized only
     * within this invocation; no public scope calls another public scope or stores permission state.
     */
    private function dependencies(Builder|Relation $query, User $user, string $type, ProjectResourceAccessPurpose $purpose, array &$denials): Builder|Relation
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return $query;
        }

        $allowed = [];
        foreach (['provider', 'server', 'website', 'repository', 'build'] as $stage) {
            $current = $stage === $type ? $query : match ($stage) {
                'provider' => $this->owned(Provider::withTrashed(), $user),
                'server' => $this->owned(Server::query(), $user),
                'website' => $this->owned(Website::withTrashed(), $user),
                'repository' => $this->owned(Repository::withTrashed(), $user),
                'build' => Build::query(),
            };
            $table = $current->getModel()->getTable();
            $this->resource($current, $user, $stage, $table.'.id', $purpose, $denials);

            if ($stage === 'server') {
                $this->requiresResource($current, 'servers.provider_id', clone $allowed['provider']);
                $this->withoutDeniedEnvironments($current, $user, 'environments', $purpose, $denials);
                $this->withoutDeniedEnvironments($current, $user, 'websites.environments', $purpose, $denials);
            } elseif ($stage === 'website') {
                $this->requiresResource($current, 'websites.server_id', clone $allowed['server']);
                $this->withoutDeniedEnvironments($current, $user, 'environments', $purpose, $denials);
            } elseif ($stage === 'repository') {
                $this->requiresResource($current, 'repositories.provider_id', clone $allowed['provider']);
                $this->requiresResource($current, 'repositories.website_id', clone $allowed['website']);
                $this->withoutDeniedEnvironments($current, $user, 'builds.environment', $purpose, $denials);
            } elseif ($stage === 'build') {
                $this->requiresResource($current, 'builds.repository_id', clone $allowed['repository']);
                $this->withoutDeniedEnvironments($current, $user, 'environment', $purpose, $denials);
            }

            if ($stage === $type) {
                return $current;
            }
            $allowed[$stage] = $current->select($table.'.id');
        }

        throw new \LogicException('Unsupported Deployer resource dependency.');
    }

    /** Non-null orphan or foreign-workspace references fail closed; genuinely absent optional links remain valid. */
    private function requiresResource(Builder|Relation $query, string $column, Builder|Relation $allowed): Builder|Relation
    {
        return $query->where(fn (Builder $resource) => $resource->whereNull($column)->orWhereIn($column, $allowed));
    }

    private function owned(Builder $query, User $user): Builder
    {
        $model = $query->getModel();

        return $query->where(fn (Builder $owned) => $owned
            ->where($model->qualifyColumn('organization_id'), $user->current_organization_id)
            ->orWhere(fn (Builder $personal) => $personal
                ->whereNull($model->qualifyColumn('organization_id'))->where($model->qualifyColumn('user_id'), $user->getKey())));
    }

    private function mutableWebsites(Builder $query, User $user, array &$denials): Builder
    {
        $this->dependencies($query, $user, 'website', ProjectResourceAccessPurpose::Interactive, $denials);
        $repositories = $this->dependencies($this->owned(Repository::withTrashed(), $user), $user, 'repository', ProjectResourceAccessPurpose::Interactive, $denials);

        return $query->whereDoesntHave('repositories', fn (Builder $repository) => $repository->withTrashed()
            ->whereNotIn('repositories.id', $repositories->select('repositories.id')));
    }

    private function mutableServers(Builder $query, User $user, array &$denials): Builder
    {
        $this->dependencies($query, $user, 'server', ProjectResourceAccessPurpose::Interactive, $denials);
        $websites = $this->mutableWebsites($this->owned(Website::withTrashed(), $user), $user, $denials);

        return $query->whereDoesntHave('websites', fn (Builder $website) => $website->withTrashed()
            ->whereNotIn('websites.id', $websites->select('websites.id')));
    }

    private function mutableEnvironments(Builder $query, User $user, array &$denials): Builder
    {
        $this->environments($query, $user);
        $servers = $this->mutableServers($this->owned(Server::query(), $user), $user, $denials)->select('servers.id');
        $websites = $this->mutableWebsites($this->owned(Website::withTrashed(), $user), $user, $denials)->select('websites.id');
        $this->requiresResource($query, 'environments.server_id', $servers);
        $this->requiresResource($query, 'environments.website_id', $websites);

        return $query;
    }

    private function denial(User $user, string $type, ProjectResourceAccessPurpose $purpose, array &$denials): ?array
    {
        if (! array_key_exists($type, $denials)) {
            $denials[$type] = $this->denied($user, $type, $purpose);
        }

        return $denials[$type];
    }

    /** @return ?list<string> */
    private function denied(User $user, string $type, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive): ?array
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return [];
        }

        $organizationId = $user->current_organization_id;
        $projects = Project::query()->where('organization_id', $organizationId)->select('projects.id');
        $environments = Environment::query()->whereIn('project_id', clone $projects);
        $query = match ($type) {
            'project' => $projects,
            'environment' => $environments,
            'server' => Server::query()->where(fn (Builder $owned) => $owned->where('organization_id', $organizationId)->orWhere(fn (Builder $personal) => $personal->whereNull('organization_id')->where('user_id', $user->getKey()))),
            'website' => Website::withTrashed()->where(fn (Builder $owned) => $owned->where('organization_id', $organizationId)->orWhere(fn (Builder $personal) => $personal->whereNull('organization_id')->where('user_id', $user->getKey()))),
            'repository' => Repository::withTrashed()->where(fn (Builder $owned) => $owned->where('organization_id', $organizationId)->orWhere(fn (Builder $personal) => $personal->whereNull('organization_id')->where('user_id', $user->getKey()))),
            'provider' => Provider::withTrashed()->where(fn (Builder $owned) => $owned->where('organization_id', $organizationId)->orWhere(fn (Builder $personal) => $personal->whereNull('organization_id')->where('user_id', $user->getKey()))),
            'status_page' => StatusPage::query()->where('organization_id', $organizationId),
            'load_balancer' => LoadBalancer::query()->where('organization_id', $organizationId),
            'build' => Build::query()->where(fn (Builder $build) => $build
                ->whereHas('repository', fn (Builder $repository) => $repository->withTrashed()->where(fn (Builder $owned) => $owned->where('organization_id', $organizationId)->orWhere(fn (Builder $personal) => $personal->whereNull('organization_id')->where('user_id', $user->getKey()))))
                ->orWhereHas('environment.project', fn (Builder $project) => $project->where('organization_id', $organizationId))),
        };

        return $this->access->deniedResourceIds(
            $user, 'deployer', $type, 'organization', (string) $organizationId,
            $query->pluck($query->getModel()->qualifyColumn('id'))->all(), $purpose,
        );
    }

    private function resource(Builder|Relation $query, User $user, string $type, string $column, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive, ?array &$denials = null): Builder|Relation
    {
        $denials ??= [];
        $denied = $this->denial($user, $type, $purpose, $denials);

        return $denied === null ? $query->whereRaw('1 = 0') : $query->whereNotIn($column, $denied);
    }

    private function withoutDeniedEnvironments(Builder|Relation $query, User $user, string $relation, ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive, ?array &$denials = null): Builder|Relation
    {
        $denials ??= [];
        $projects = $this->denial($user, 'project', $purpose, $denials);
        $environments = $this->denial($user, 'environment', $purpose, $denials);

        if ($projects === null || $environments === null) {
            return $query->whereRaw('1 = 0');
        }

        if (app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            $query->whereDoesntHave($relation, fn (Builder $environment) => $environment->whereDoesntHave(
                'project', fn (Builder $project) => $project->where('organization_id', $user->current_organization_id),
            ));
        }

        if ($projects !== [] || $environments !== []) {
            $query->whereDoesntHave($relation, fn (Builder $environment) => $environment->where(
                fn (Builder $denied) => $denied->whereIn('environments.project_id', $projects)->orWhereIn('environments.id', $environments),
            ));
        }

        return $query;
    }
}
