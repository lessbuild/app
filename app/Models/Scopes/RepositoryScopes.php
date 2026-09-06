<?php

namespace App\Models\Scopes;

use App\Enums\BuildStatus;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-require-extends Repository
 */
trait RepositoryScopes
{
    /**
     * Limit the query to repositories without any deployment history.
     *
     * @param  Builder<Repository>  $query  The query to constrain.
     * @return Builder<Repository> The same query with the scope applied.
     */
    public function scopeNeverDeployed(Builder $query): Builder
    {
        return $query->whereDoesntHave('builds');
    }

    /**
     * Limit the query by the most recent build, preserving older build history.
     *
     * @param  Builder<Repository>  $query  The query to constrain.
     * @param  BuildStatus|string|list<BuildStatus|string>  $statuses  Accepted latest-build statuses.
     * @return Builder<Repository> The same query with the scope applied.
     */
    public function scopeLatestBuildStatus(Builder $query, BuildStatus|string|array $statuses): Builder
    {
        return $query->whereHas('latestBuild', fn (Builder $query): Builder => $query
            ->whereIn('status', is_array($statuses) ? $statuses : [$statuses]));
    }
}
