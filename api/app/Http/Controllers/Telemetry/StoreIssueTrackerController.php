<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Monitoring\SaveIssueTracker;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreIssueTrackerController
{
    /**
     * Connect a ticket tracker and return to the setup page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveIssueTracker  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SaveIssueTracker $save): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:github,linear,jira'],
            'name' => ['nullable', 'string', 'max:80'],
            'repository' => ['nullable', 'string', 'max:200'], 'token' => ['nullable', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'], 'team_id' => ['nullable', 'string', 'max:100'],
            'site' => ['nullable', 'string', 'max:200'], 'email' => ['nullable', 'string', 'max:200'], 'project_key' => ['nullable', 'string', 'max:20'],
        ]);
        $tracker = $save->handle($user, $project, $data['kind'], (string) ($data['name'] ?? ''), $data);

        return to_route('monitoring.setup', $project)->withFragment('trackers')->with('status', __(':name is connected.', ['name' => $tracker->name]));
    }
}
