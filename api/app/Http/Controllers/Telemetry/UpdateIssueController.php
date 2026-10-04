<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\UpdateIssue;
use App\Http\Requests\Telemetry\UpdateIssueRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateIssueController
{
    /**
     * Resolve, reopen, snooze, ignore, assign or annotate an issue.
     *
     * @param  UpdateIssueRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Issue  $issue
     * @param  UpdateIssue  $update
     * @return JsonResponse
     */
    public function __invoke(UpdateIssueRequest $request, #[CurrentUser] User $user, Project $project, Issue $issue, UpdateIssue $update): JsonResponse
    {
        $data = $request->validated();
        $update->handle($issue, $user, [
            'action' => (string) $data['action'],
            'version' => (int) $data['version'],
            ...(array_key_exists('assignee_id', $data) ? ['assignee_id' => is_string($data['assignee_id']) ? $data['assignee_id'] : null] : []),
            ...(isset($data['snooze_minutes']) ? ['snooze_minutes' => (int) $data['snooze_minutes']] : []),
            'note' => is_string($data['note'] ?? null) ? $data['note'] : null,
        ]);

        return response()->json(['redirect' => route('monitoring.issues.show', [$project, $issue->id], false), 'message' => __('Issue updated.')]);
    }
}
