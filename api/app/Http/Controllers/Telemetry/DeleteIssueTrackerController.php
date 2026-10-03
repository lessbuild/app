<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Monitoring\RemoveIssueTracker;
use App\Models\IssueTracker;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteIssueTrackerController
{
    /**
     * Disconnect a ticket tracker and return to the setup page. Another project's trackers are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $tracker
     * @param  RemoveIssueTracker  $remove
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $tracker, RemoveIssueTracker $remove): RedirectResponse
    {
        $remove->handle($user, IssueTracker::query()->where('project_id', $project->id)->findOrFail($tracker));

        return to_route('monitoring.setup', $project)->withFragment('trackers')->with('status', __('Tracker disconnected.'));
    }
}
