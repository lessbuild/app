<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DeleteGoal;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteGoalController
{
    /**
     * Delete a goal and removes it from the site's history.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  string  $goal
     * @param  DeleteGoal  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, string $goal, DeleteGoal $delete): JsonResponse
    {
        $delete->handle($user, $site->goals()->findOrFail($goal));

        return response()->json(['redirect' => route('analytics.goals', [$project, 'site' => $site->id], false), 'message' => __('Goal removed.')]);
    }
}
