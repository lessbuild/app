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
     * Reads for the Deployer API v1, scoped to the token's account and to what its person may use.
     *
     * @param  Entitlements  $entitlements  Reads the account's Deploy tier for `/me`.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * The account's projects whose Deploy the person may use, with their environments.
     *
     * @return Builder<Project>
     */
    public function projects(User $user, Account $account): Builder
    {
        return Project::query()->where('account_id', $account->id)
            ->whereIn('id', Project::query()->where('account_id', $account->id)->get()->filter(fn (Project $project): bool => $user->can('useService', [$project, 'deploy']))->modelKeys())
            ->with('environments');
    }

    /**
     * One such project; 404 outside the account, 403 when the person may not use its Deploy.
     */
    public function project(User $user, Account $account, string $id): Project
    {
        $project = Project::query()->where('account_id', $account->id)->with('environments')->findOrFail($id);
        Gate::forUser($user)->authorize('useService', [$project, 'deploy']);

        return $project;
    }

    /**
     * Deploys of repositories in the projects the person may use, including repositories disconnected since.
     *
     * @return Builder<Build>
     */
    public function builds(User $user, Account $account): Builder
    {
        return Build::query()->whereIn('repository_id', \App\Models\Repository::withTrashed()->whereIn('project_id', $this->projects($user, $account)->select('id'))->select('id'));
    }

    /**
     * One such deploy with its repository, website and environment; 404 when it's outside them.
     */
    public function build(User $user, Account $account, string $id): Build
    {
        $build = $this->builds($user, $account)->with(['repository', 'website', 'environment'])->findOrFail((int) $id);
        Gate::forUser($user)->authorize('view', $build);

        return $build;
    }

    /**
     * An environment of the account, checked the same way as its project.
     */
    public function environment(User $user, Account $account, string $id): Environment
    {
        $environment = Environment::query()->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'))->with('project')->findOrFail($id);
        Gate::forUser($user)->authorize('useService', [$environment->project, 'deploy']);

        return $environment;
    }

    /**
     * Deployer's optional cursor pagination: without `limit` or `cursor` the whole (bounded) list is returned.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  'asc'|'desc'  $direction
     * @return array{items: array<int, TModel>, meta: array{limit: int, next_cursor: string|null}|null}
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
     * The `/me` payload: the person and the account ("organization" in Deployer's API) with its Deploy plan.
     *
     * @return array<string, mixed>
     */
    public function account(User $user, Account $account): array
    {
        $tier = $this->entitlements->tierFor($account, 'deploy') ?? DeployCatalog::billing()->defaultTier();

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'organization' => ['id' => $account->id, 'name' => $account->name, 'plan' => $tier->key]];
    }

    /**
     * A project as the API returns it, with its environments. `state` is always "running" until hibernation exists.
     *
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
     * A deploy as the API returns it.
     *
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
}
