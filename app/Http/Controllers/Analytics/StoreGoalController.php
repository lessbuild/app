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

final class StoreGoalController
{
    /**
     * Create a goal on a site.
     *
     * @param  GoalRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  SaveGoal  $save
     * @return RedirectResponse
     */
    public function __invoke(GoalRequest $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, SaveGoal $save): RedirectResponse
    {
        $save->handle($user, $site, $request->toDetails());

        return to_route('analytics.goals', [$project, 'site' => $site->id])->with('status', __('Goal created. It counts conversions from now on.'));
    }
}
