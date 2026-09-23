<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\IssueStatus;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ChangeIssue
{
    public function __construct(private readonly TelemetryRedactor $redactor) {}

    /** @param array{action: string, version: int|string, assignee_id?: int|string|null, snooze_minutes?: int|string, note?: string|null} $data */
    public function update(Issue $issue, Workspace $workspace, User $actor, array $data): Issue
    {
        return DB::connection('monitor')->transaction(function () use ($issue, $workspace, $actor, $data): Issue {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $application = Application::query()->whereBelongsTo($workspace)->lockForUpdate()->findOrFail($issue->application_id);
            $application->setRelation('workspace', $workspace);
            $issue = Issue::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($issue->id);
            $issue->setRelation('application', $application);
            Gate::forUser($actor)->authorize('update', $issue);
            abort_unless($issue->state_version === (int) $data['version'], 409, 'This issue changed since you opened it. Refresh before trying again.');
            $now = CarbonImmutable::now('UTC');
            $before = ['status' => $issue->status->value, 'assignee_id' => $issue->assignee_id, 'snoozed_until' => $issue->snoozed_until?->toISOString()];

            if ($data['action'] === 'assign') {
                $assigneeId = isset($data['assignee_id']) ? (int) $data['assignee_id'] : null;

                if ($assigneeId !== null && ! $workspace->members()->whereKey($assigneeId)->wherePivotIn('role', ['owner', 'admin', 'member'])->exists()) {
                    throw ValidationException::withMessages(['assignee_id' => 'Choose a current workspace contributor.']);
                }

                $issue->forceFill(['assignee_id' => $assigneeId]);
            } elseif ($data['action'] === 'snooze') {
                abort_unless(in_array($issue->status, [IssueStatus::Open, IssueStatus::Snoozed], true), 409, 'Reopen this issue before snoozing it.');
                $issue->forceFill(['status' => IssueStatus::Snoozed, 'snoozed_until' => $now->addMinutes((int) $data['snooze_minutes']), 'resolved_at' => null]);
            } else {
                $status = match ($data['action']) {
                    'resolve' => IssueStatus::Resolved,
                    'reopen' => IssueStatus::Open,
                    'ignore' => IssueStatus::Ignored,
                };

                if ($status !== $issue->status) {
                    $issue->forceFill(['status' => $status, 'snoozed_until' => null, 'resolved_at' => $status === IssueStatus::Resolved ? $now : null]);
                }
            }

            if (! $issue->isDirty()) {
                return $issue;
            }

            $issue->forceFill(['state_version' => $issue->state_version + 1])->save();
            $issue->activities()->create([
                'actor_id' => $actor->id, 'action' => $data['action'],
                'metadata' => ['before' => $before, 'status' => $issue->status->value, 'assignee_id' => $issue->assignee_id, 'snoozed_until' => $issue->snoozed_until?->toISOString()],
                'note' => $this->redactor->redact(['note' => $data['note'] ?? null])['note'],
            ]);

            return $issue;
        }, attempts: 3);
    }

    /** The caller holds the workspace lock while changing membership. */
    public function unassignMember(Workspace $workspace, User $member, User $actor): void
    {
        Issue::query()->whereIn('application_id', $workspace->applications()->withTrashed()->select('id'))
            ->where('assignee_id', $member->id)->orderBy('id')->lockForUpdate()
            ->eachById(function (Issue $issue) use ($member, $actor): void {
                $issue->forceFill(['assignee_id' => null, 'state_version' => $issue->state_version + 1])->save();
                $issue->activities()->create([
                    'actor_id' => $actor->id, 'action' => 'assignee_unavailable',
                    'metadata' => ['before' => ['assignee_id' => $member->id], 'assignee_id' => null],
                ]);
            }, 100);
    }
}
