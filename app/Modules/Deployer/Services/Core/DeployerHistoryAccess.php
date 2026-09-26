<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\ScheduledTask;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerCommandExecution;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Recipient history stays global across authorized workspaces; source payloads require current access. */
final class DeployerHistoryAccess
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly DeployerProjectAccess $projects,
    ) {}

    public function notifications(Builder|Relation $query, User $user): Builder|Relation
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $query;
        }

        $contexts = $this->contexts($user);
        $categoryColumn = $query->getQuery()->getGrammar()->wrap('data->category');
        $categories = (clone $query)->reorder()->select([])->selectRaw($categoryColumn.' AS history_category')->distinct()->get()->pluck('history_category')->all();

        return $query->where(function (Builder $visible) use ($contexts, $categories): void {
            // Account security and gallery correspondence are recipient-owned, independently of projects.
            $visible->whereIn('data->category', ['account', 'recipe', 'gallery']);
            foreach (['deployment' => 'builds', 'server' => 'servers', 'website' => 'websites', 'provider' => 'providers', 'metric' => 'metrics', 'scheduled_task' => 'tasks'] as $category => $resource) {
                if (! in_array($category, $categories, true)) {
                    continue;
                }
                $source = $this->sourceQuery($contexts, $resource);
                $visible->orWhere(fn (Builder $notification) => $notification->where('data->category', $category)
                    ->whereIn('data->resource_id', $source->select($source->getModel()->qualifyColumn('id'))));
            }
        });
    }

    public function activity(Builder|Relation $query, User $user): Builder|Relation
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $query;
        }

        $contexts = $this->contexts($user);
        $sourceTypes = (clone $query)->reorder()->distinct()->pluck('parentable_type')->all();

        return $query->where(function (Builder $visible) use ($user, $contexts, $sourceTypes): void {
            $visible->where(fn (Builder $account) => $account->whereIn('parentable_type', $this->morphTypes(User::class))
                ->where('parentable_id', $user->getKey()));
            $visible->orWhere(fn (Builder $recipe) => $recipe->whereIn('parentable_type', $this->morphTypes(Recipe::class))
                ->whereIn('parentable_id', Recipe::query()->where(fn (Builder $owned) => $owned->where('user_id', $user->getKey())
                    ->orWhere('is_published', true))->select('recipes.id')));

            foreach ([
                Project::class => 'projects', Environment::class => 'environments', Build::class => 'builds',
                Server::class => 'servers', Website::class => 'websites', Repository::class => 'repositories',
                Provider::class => 'providers', ServerCommandExecution::class => 'commands',
            ] as $model => $resource) {
                if (array_intersect($this->morphTypes($model), $sourceTypes) === []) {
                    continue;
                }
                $source = $this->sourceQuery($contexts, $resource);
                $visible->orWhere(fn (Builder $event) => $event->whereIn('parentable_type', $this->morphTypes($model))
                    ->whereIn('parentable_id', $source->select($source->getModel()->qualifyColumn('id'))));
            }
        });
    }

    /** @return list<User> */
    private function contexts(User $user): array
    {
        return Organization::query()
            ->where(fn (Builder $organization) => $organization->where('owner_id', $user->getKey())
                ->orWhereHas('members', fn (Builder $member) => $member->whereKey($user->getKey())))
            ->get()
            ->map(function (Organization $organization) use ($user): User {
                $context = clone $user;
                $context->setAttribute('current_organization_id', $organization->getKey());
                $context->setRelation('currentOrganization', $organization);

                return $context;
            })->all();
    }

    /** @param list<User> $contexts */
    private function sourceQuery(array $contexts, string $resource): Builder
    {
        $model = match ($resource) {
            'projects' => Project::class, 'environments' => Environment::class, 'builds' => Build::class,
            'servers' => Server::class, 'websites' => Website::class, 'repositories' => Repository::class,
            'providers' => Provider::class, 'commands' => ServerCommandExecution::class,
            'metrics' => MetricAlertRule::class, 'tasks' => ScheduledTask::class,
        };

        return $model::query()->where(function (Builder $allowed) use ($contexts, $resource): void {
            $allowed->whereRaw('1 = 0');
            foreach ($contexts as $context) {
                $allowed->orWhere(function (Builder $owned) use ($context, $resource): void {
                    if ($resource === 'metrics') {
                        $allResources = $this->projects->canAccessWorkspaceResources($context);
                        $owned->where('organization_id', $context->current_organization_id)
                            ->where(function (Builder $rule) use ($context, $allResources): void {
                                $rule->whereIn('server_id', $this->projects->servers(
                                    Server::query()->where('organization_id', $context->current_organization_id), $context,
                                )->select('servers.id'));
                                if ($allResources) {
                                    $rule->orWhereNull('server_id');
                                }
                            });

                        return;
                    }
                    if ($resource === 'tasks') {
                        $owned->whereIn('environment_id', $this->projects->environments(
                            Environment::query()->whereHas('project', fn (Builder $project) => $project->where('organization_id', $context->current_organization_id)), $context,
                        )->select('environments.id'));

                        return;
                    }
                    if ($resource === 'commands') {
                        $owned->whereIn('server_id', $this->projects->servers(
                            Server::query()->where('organization_id', $context->current_organization_id), $context,
                        )->select('servers.id'));

                        return;
                    }
                    if ($resource === 'environments') {
                        $owned->whereHas('project', fn (Builder $project) => $project->where('organization_id', $context->current_organization_id));
                    } elseif ($resource === 'builds') {
                        $owned->whereHas('repository', fn (Builder $repository) => $repository->where('organization_id', $context->current_organization_id)
                            ->orWhere(fn (Builder $personal) => $personal->whereNull('organization_id')->where('user_id', $context->getKey())));
                    } else {
                        $owned->where(function (Builder $resourceQuery) use ($context, $resource): void {
                            $resourceQuery->where('organization_id', $context->current_organization_id);
                            if ($resource !== 'projects') {
                                $resourceQuery->orWhere(fn (Builder $personal) => $personal->whereNull('organization_id')->where('user_id', $context->getKey()));
                            }
                        });
                    }
                    $this->projects->{$resource}($owned, $context);
                });
            }
        });
    }

    /** @param class-string $model @return list<string> */
    private function morphTypes(string $model): array
    {
        return array_values(array_unique([(new $model)->getMorphClass(), $model, 'App\\Models\\'.class_basename($model)]));
    }
}
