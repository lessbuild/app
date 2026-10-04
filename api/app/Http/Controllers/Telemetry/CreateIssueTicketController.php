<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Monitoring\CreateTicketFromIssue;
use App\Models\Issue;
use App\Models\IssueTracker;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreateIssueTicketController
{
    /**
     * File a ticket for the issue in the chosen tracker and return to the issue.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Issue  $issue
     * @param  CreateTicketFromIssue  $create
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Issue $issue, CreateTicketFromIssue $create): JsonResponse
    {
        $data = $request->validate(['tracker' => ['required', 'integer']]);
        $create->handle($user, $issue, IssueTracker::query()->where('project_id', $project->id)->findOrFail((int) $data['tracker']));

        return response()->json(['redirect' => route('monitoring.issues.show', [$project, $issue->id], false), 'message' => __('Ticket :key created.', ['key' => $issue->refresh()->ticket_key])]);
    }
}
