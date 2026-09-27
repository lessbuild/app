<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DeleteGoal;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteGoalController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $site, string $goal, ProjectSitesQuery $sites, DeleteGoal $delete): RedirectResponse
    {
        $target = $sites->find($project, $site);
        $delete->handle($user, $target->goals()->findOrFail($goal));

        return to_route('analytics.goals', [$project, 'site' => $target->id])->with('status', __('Goal removed.'));
    }
}
