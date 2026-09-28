<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Models\Account;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Platform\Catalog\DeployCatalog;
use App\Services\Billing\Entitlements;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records the Deployer API v1 works with, found only inside the token's account and authorised with the same policies
 * as the pages, plus the JSON shapes Deployer's API returned.
 */
final class DeployApiQuery
{
    /**
     * Create a new DeployApiQuery instance.
     *
     * Reads for the Deployer API v1, scoped to the token's account and to what its person may use.
     *
     * @param  Entitlements  $entitlements  Reads the account's Deploy tier for `/me`.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Query the account's projects whose Deploy the person may use, with their environments.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return Builder<Project>
     */
    public function projects(User $user, Account $account): Builder
    {
        return Project::query()->where('account_id', $account->id)
            ->whereIn('id', Project::query()->where('account_id', $account->id)->get()->filter(fn (Project $project): bool => $user->can('useService', [$project, 'deploy']))->modelKeys())
            ->with('environments');
    }

    /**
     * Get one such project by its ID, or by Deployer's numeric ID; 404 outside the account, 403 when the person may not
     * use its Deploy.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $id
     * @return Project
     */
    public function project(User $user, Account $account, string $id): Project
    {
        $project = self::byId(Project::query()->where('account_id', $account->id)->with('environments'), $id);
        Gate::forUser($user)->authorize('useService', [$project, 'deploy']);

        return $project;
    }

    /**
     * Query the deploys of repositories in the projects the person may use, including repositories disconnected since.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return Builder<Build>
     */
    public function builds(User $user, Account $account): Builder
    {
        return Build::query()->whereIn('repository_id', \App\Models\Repository::withTrashed()->whereIn('project_id', $this->projects($user, $account)->select('id'))->select('id'));
    }

    /**
     * Get one such deploy with its repository, website and environment; 404 when it's outside them.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $id
     * @return Build
     */
    public function build(User $user, Account $account, string $id): Build
    {
        $build = $this->builds($user, $account)->with(['repository', 'website', 'environment'])->findOrFail((int) $id);
        Gate::forUser($user)->authorize('view', $build);

        return $build;
    }

    /**
     * Get an environment of the account by its ID or Deployer's numeric ID, checked the same way as its project.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $id
     * @return Environment
     */
    public function environment(User $user, Account $account, string $id): Environment
    {
        $environment = self::byId(Environment::query()->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'))->with('project'), $id);
        Gate::forUser($user)->authorize('useService', [$environment->project, 'deploy']);

        return $environment;
    }

    /**
     * Paginate a list the way Deployer's API does: without `limit` or `cursor` the whole (bounded) list is returned.
     *
     * @param  Builder<TModel>  $query
     * @param  mixed  $limit
     * @param  mixed  $cursor
     * @param  'asc'|'desc'  $direction
     * @param  int  $unpaged
     * @return array{items: array<int, TModel>, meta: array{limit: int, next_cursor: string|null}|null}
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     */
    public function page(Builder $query, mixed $limit, mixed $cursor, string $direction, int $unpaged): array
    {
        if ($limit === null && $cursor === null) {
            return ['items' => $query->orderBy('id', $direction)->limit($unpaged)->get()->all(), 'meta' => null];
        }
        $size = filter_var($limit ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $position = is_string($cursor) ? Cursor::fromEncoded($cursor) : null;
        if ($size === false || ($cursor !== null && $position === null)) {
            throw ValidationException::withMessages(['cursor' => __('The limit or cursor is invalid.')]);
        }
        /** @var CursorPaginator<int, TModel> $page */
        $page = $query->reorder()->orderBy('id', $direction)->cursorPaginate($size, ['*'], 'cursor', $position);

        return ['items' => $page->items(), 'meta' => ['limit' => $size, 'next_cursor' => $page->nextCursor()?->encode()]];
    }

    /**
     * Build the `/me` payload: the person and the account ("organization" in Deployer's API) with its Deploy plan.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return array<string, mixed>
     */
    public function account(User $user, Account $account): array
    {
        $tier = $this->entitlements->tierFor($account, 'deploy') ?? DeployCatalog::billing()->defaultTier();

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'organization' => ['id' => $account->id, 'name' => $account->name, 'plan' => $tier->key]];
    }

    /**
     * Format a project as the API returns it, with its environments. `state` is always "running" until hibernation
     * exists.
     *
     * @param  Project  $project
     * @return array<string, mixed>
     */
    public function projectData(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'environments' => $project->environments->map(fn (Environment $environment): array => [
            'id' => $environment->id, 'name' => $environment->name, 'slug' => $environment->slug, 'type' => $environment->kind->value,
            'desired_replicas' => $environment->desired_replicas, 'state' => 'running',
        ])->values()->all()];
    }

    /**
     * Format a deploy as the API returns it.
     *
     * @param  Build  $build
     * @return array<string, mixed>
     */
    public function buildData(Build $build): array
    {
        return [
            'id' => $build->id, 'repository_id' => $build->repository_id, 'environment_id' => $build->environment_id, 'status' => $build->status,
            'trigger' => $build->trigger_source, 'revision' => $build->revision, 'promoted_from_build_id' => $build->promoted_from_build_id,
            'created_at' => $build->created_at?->toIso8601String(), 'finished_at' => $build->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Find a record by its v2 ID, or by Deployer's numeric ID (`legacy_id`) when the path has a number, so scripts
     * written against Deployer keep working; 404 when there's none.
     *
     * @param  Builder<TModel>  $query
     * @param  string  $id
     * @return TModel
     *
     * @template TModel of Project|Environment
     */
    private static function byId(Builder $query, string $id): Model
    {
        return (ctype_digit($id) ? $query->where('legacy_id', (int) $id) : $query->whereKey($id))->firstOrFail();
    }
}
