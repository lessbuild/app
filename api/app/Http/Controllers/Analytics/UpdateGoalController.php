<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveGoal;
use App\Http\Requests\Analytics\GoalRequest;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateGoalController
{
    /**
     * Change a goal; the new definition counts from now on.
     *
     * @param  GoalRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  string  $goal
     * @param  SaveGoal  $save
     * @return RedirectResponse
     */
    public function __invoke(GoalRequest $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, string $goal, SaveGoal $save): RedirectResponse
    {
        $save->handle($user, $site, $request->toDetails(), $site->goals()->findOrFail($goal));

        return to_route('analytics.goals', [$project, 'site' => $site->id])->with('status', __('Goal saved. The new definition counts from now on.'));
    }
}
