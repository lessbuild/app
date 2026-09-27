<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Account;
use App\Models\Project;

final class ProjectSwitcherQuery
{
    /**
     * The account's projects by name, for the project switcher.
     *
     * @return list<array{id: string, name: string}>
     */
    public function handle(Account $account, int $limit = 50): array
    {
        return array_values(Project::query()->where('account_id', $account->id)->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
            ->all());
    }
}
