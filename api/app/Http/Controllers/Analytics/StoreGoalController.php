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
     * @return JsonResponse
     */
    public function __invoke(GoalRequest $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, SaveGoal $save): JsonResponse
    {
        $save->handle($user, $site, $request->toDetails());

        return response()->json(['redirect' => route('analytics.goals', [$project, 'site' => $site->id], false), 'message' => __('Goal created. It counts conversions from now on.')]);
    }
}
