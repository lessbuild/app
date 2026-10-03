<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Account;
use App\Models\Project;
use App\Models\User;

final class ProjectSwitcherQuery
{
    /**
     * Create a new ProjectSwitcherQuery instance.
     *
     * @param  VisibleProjects  $visible  Limits the list to the projects the person can see.
     */
    public function __construct(private readonly VisibleProjects $visible) {}

    /**
     * Get the account's projects by name, for the project switcher.
     *
     * @param  Account  $account
     * @param  int  $limit
     * @param  User|null  $user  limits the list to the projects they can see
     * @return list<array{id: string, name: string}>
     */
    public function handle(Account $account, int $limit = 50, ?User $user = null): array
    {
        return array_values($this->visible->scope(Project::query()->where('account_id', $account->id), $account->id, $user)->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
            ->all());
    }
}
