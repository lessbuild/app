<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\VisibleProjects;
use Illuminate\Database\Eloquent\Collection;

final class AccountSitesQuery
{
    /**
     * Create a new AccountSitesQuery instance.
     *
     * @param  VisibleProjects  $visible  Limits people with per-project access to their projects.
     */
    public function __construct(private readonly VisibleProjects $visible) {}

    /**
     * Get the account's analytics sites in the projects the person can see, by name.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return Collection<int, AnalyticsSite>
     */
    public function handle(Account $account, User $user): Collection
    {
        $projects = $this->visible->scope(Project::query()->where('account_id', $account->id), $account->id, $user)->select('projects.id');

        return AnalyticsSite::query()->whereIn('project_id', $projects)->with('project')->orderBy('name')->orderBy('id')->get();
    }
}
