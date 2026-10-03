<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Narrows project lists to the projects a member may see, for members limited to some projects. */
final class VisibleProjects
{
    /**
     * Limit a query on the account's projects to those the person can see (no change for people who see them all).
     *
     * @param  Builder<Project>  $query
     * @param  string  $accountId
     * @param  User|null  $user  null leaves the query as it is
     * @return Builder<Project>
     */
    public function scope(Builder $query, string $accountId, ?User $user): Builder
    {
        $ids = $this->ids($accountId, $user);

        return $ids === null ? $query : $query->whereIn('projects.id', $ids);
    }

    /**
     * Get the project IDs the person is limited to in the account, or null when they see every project.
     *
     * @param  string  $accountId
     * @param  User|null  $user
     * @return list<string>|null
     */
    public function ids(string $accountId, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }
        $membership = Membership::query()->where('account_id', $accountId)->where('user_id', $user->id)->first();
        if ($membership === null) {
            return [];
        }

        return $membership->canSeeProject('') ? null : ($membership->project_ids ?? []);
    }
}
