<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveGoal;
use App\Http\Requests\Analytics\GoalRequest;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

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
     * @return JsonResponse
     */
    public function __invoke(GoalRequest $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, string $goal, SaveGoal $save): JsonResponse
    {
        $save->handle($user, $site, $request->toDetails(), $site->goals()->findOrFail($goal));

        return response()->json(['redirect' => route('analytics.goals', [$project, 'site' => $site->id], false), 'message' => __('Goal saved. The new definition counts from now on.')]);
    }
}
