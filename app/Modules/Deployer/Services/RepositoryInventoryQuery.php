<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerResourceProjection;
use App\Modules\Deployer\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

class RepositoryInventoryQuery
{
    /**
     * Build the organization-scoped repository query shared by the inventory page and export.
     *
     * @param  array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string}  $filters
     * @return HasMany<Repository, Organization> The filtered repository relationship for the current workspace.
     */
    public function for(User $user, array $filters): HasMany
    {
        return $user->workspaceRepositories()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("url LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['provider_id'], fn ($query, int $id) => $query
                ->where('provider_id', $id))
            ->when($filters['website_id'], fn ($query, int $id) => $query
                ->where('website_id', $id))
            ->when($filters['status'] === 'none', fn ($query) => $query->neverDeployed())
            ->when($filters['status'] && $filters['status'] !== 'none', fn ($query) => $this->latestBuildStatus($query, $user, $filters['status']));
    }

    /**
     * Calculate repository inventory counters from the same filtered workspace query used by the listing.
     *
     * @param  array{search: ?string, provider_id: ?int, website_id: ?int, status: ?string}  $filters
     * @return array{total: int, never_deployed: int, active: int, succeeded: int, failed: int, webhooks: int}
     */
    public function metrics(User $user, array $filters): array
    {
        return [
            'total' => $this->for($user, $filters)->count(),
            'never_deployed' => $this->for($user, $filters)->neverDeployed()->count(),
            'active' => $this->latestBuildStatus($this->for($user, $filters), $user, Build::ACTIVE_STATUSES)->count(),
            'succeeded' => $this->latestBuildStatus($this->for($user, $filters), $user, Build::STATUS_SUCCEEDED)->count(),
            'failed' => $this->latestBuildStatus($this->for($user, $filters), $user, Build::STATUS_FAILED)->count(),
            'webhooks' => $this->for($user, $filters)
                ->where('webhook_enabled', true)
                ->count(),
        ];
    }

    /** A denied latest build has no visible status; an older result must not become the latest implicitly. */
    private function latestBuildStatus(Builder|Relation $query, User $actor, string|array $statuses): Builder|Relation
    {
        return $query->whereHas('latestBuild', fn (Builder $builds) => app(DeployerResourceProjection::class)
            ->builds($builds, $actor)->whereIn('status', is_array($statuses) ? $statuses : [$statuses]));
    }
}
