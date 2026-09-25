<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\ScheduledTask;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Explicit interactive-query restrictions; unattended product work keeps its own authority. */
final class DeployerProjectAccess
{
    public function __construct(private readonly MappedProjectResourceAccess $access) {}

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

    public function projects(Builder|Relation $query, User $user): Builder|Relation
    {
        $this->resource($query, $user, 'project', 'projects.id');
        $deniedEnvironments = $this->denied($user, 'environment');

        if ($deniedEnvironments === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($deniedEnvironments !== []) {
            $query->whereDoesntHave('environments', fn (Builder $environment) => $environment->whereIn('environments.id', $deniedEnvironments));
        }

        return $query;
    }

    public function environments(Builder|Relation $query, User $user): Builder|Relation
    {
        $this->resource($query, $user, 'environment', 'environments.id');
        $deniedProjects = $this->denied($user, 'project');

        return $deniedProjects === null
            ? $query->whereRaw('1 = 0')
            : $query->whereNotIn('environments.project_id', $deniedProjects);
    }

    public function websites(Builder|Relation $query, User $user): Builder|Relation
    {
        $this->resource($query, $user, 'website', 'websites.id');

        return $this->withoutDeniedEnvironments($query, $user, 'environments');
    }

    public function servers(Builder|Relation $query, User $user): Builder|Relation
    {
        $this->resource($query, $user, 'server', 'servers.id');
        $this->withoutDeniedEnvironments($query, $user, 'environments');

        return $this->withoutDeniedEnvironments($query, $user, 'websites.environments');
    }

    public function repositories(Builder|Relation $query, User $user): Builder|Relation
    {
        $this->resource($query, $user, 'repository', 'repositories.id');
        $this->withoutDeniedEnvironments($query, $user, 'website.environments');

        return $this->withoutDeniedEnvironments($query, $user, 'builds.environment');
    }

    public function builds(Builder|Relation $query, User $user): Builder|Relation
    {
        $this->resource($query, $user, 'build', 'builds.id');
        $this->withoutDeniedEnvironments($query, $user, 'environment');

        return $this->withoutDeniedEnvironments($query, $user, 'repository.website.environments');
    }

    /** Scope operational incident categories before pagination, totals, or export. */
    public function incidents(Builder|Relation $query, User $user): Builder|Relation
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return $query;
        }

        $sources = [
            'deployment' => $this->builds(Build::query()->where(fn (Builder $build) => $build->whereHas('repository', fn (Builder $repository) => $repository->where('organization_id', $user->current_organization_id))->orWhereHas('environment.project', fn (Builder $project) => $project->where('organization_id', $user->current_organization_id))), $user)->select('builds.id'),
            'website' => $this->websites(Website::query()->where('organization_id', $user->current_organization_id), $user)->select('websites.id'),
            'server' => $this->servers(Server::query()->where('organization_id', $user->current_organization_id), $user)->select('servers.id'),
            'metric' => MetricAlertRule::query()->where('organization_id', $user->current_organization_id)->where(fn (Builder $rule) => $rule->whereNull('server_id')
                ->orWhereIn('server_id', $this->servers(Server::query()->where('organization_id', $user->current_organization_id), $user)->select('servers.id')))->select('id'),
            'scheduled_task' => ScheduledTask::query()->whereIn('environment_id', $this->environments(Environment::query()->whereHas('project', fn (Builder $project) => $project->where('organization_id', $user->current_organization_id)), $user)->select('environments.id'))->select('id'),
        ];
        foreach ($sources as $category => $source) {
            $query->where(fn (Builder $incident) => $incident->where('category', '!=', $category)
                ->orWhereIn('resource_id', $source));
        }

        return $query;
    }

    /** @return ?list<string> */
    private function denied(User $user, string $type): ?array
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
            'server' => Server::query()->where('organization_id', $organizationId),
            'website' => Website::withTrashed()->where('organization_id', $organizationId),
            'repository' => Repository::withTrashed()->where('organization_id', $organizationId),
            'build' => Build::query()->where(fn (Builder $build) => $build
                ->whereHas('repository', fn (Builder $repository) => $repository->where('organization_id', $organizationId))
                ->orWhereHas('environment.project', fn (Builder $project) => $project->where('organization_id', $organizationId))),
        };

        return $this->access->deniedResourceIds(
            $user, 'deployer', $type, 'organization', (string) $organizationId,
            $query->pluck($query->getModel()->qualifyColumn('id'))->all(),
        );
    }

    private function resource(Builder|Relation $query, User $user, string $type, string $column): Builder|Relation
    {
        $denied = $this->denied($user, $type);

        return $denied === null ? $query->whereRaw('1 = 0') : $query->whereNotIn($column, $denied);
    }

    private function withoutDeniedEnvironments(Builder|Relation $query, User $user, string $relation): Builder|Relation
    {
        $projects = $this->denied($user, 'project');
        $environments = $this->denied($user, 'environment');

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
