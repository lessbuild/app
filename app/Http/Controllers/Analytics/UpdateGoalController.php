<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveGoal;
use App\Http\Requests\Analytics\GoalRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateGoalController
{
    public function __invoke(GoalRequest $request, #[CurrentUser] User $user, Project $project, string $site, string $goal, ProjectSitesQuery $sites, SaveGoal $save): RedirectResponse
    {
        $target = $sites->find($project, $site);
        $save->handle($user, $target, $request->toDetails(), $target->goals()->findOrFail($goal));

        return to_route('analytics.goals', [$project, 'site' => $target->id])->with('status', __('Goal saved. The new definition counts from now on.'));
    }
}
