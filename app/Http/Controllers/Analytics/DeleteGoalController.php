<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DeleteGoal;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteGoalController
{
    /**
     * Deletes a goal and removes it from the site's history.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, string $goal, DeleteGoal $delete): RedirectResponse
    {
        $delete->handle($user, $site->goals()->findOrFail($goal));

        return to_route('analytics.goals', [$project, 'site' => $site->id])->with('status', __('Goal removed.'));
    }
}
