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

final class StoreGoalController
{
    public function __invoke(GoalRequest $request, #[CurrentUser] User $user, Project $project, string $site, ProjectSitesQuery $sites, SaveGoal $save): RedirectResponse
    {
        $target = $sites->find($project, $site);
        $save->handle($user, $target, $request->toDetails());

        return to_route('analytics.goals', [$project, 'site' => $target->id])->with('status', __('Goal created. It counts conversions from now on.'));
    }
}
