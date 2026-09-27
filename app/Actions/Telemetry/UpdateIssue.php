<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Enums\IssueStatus;
use App\Exceptions\StateConflict;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateIssue
{
    /**
     * Resolves, reopens, snoozes, ignores or assigns an issue.
     *
     * @param  TelemetryRedactor  $redactor  Redacts notes before they're stored.
     */
    public function __construct(private readonly TelemetryRedactor $redactor) {}

    /**
     * Resolve, reopen, snooze, ignore or assign an issue. A new occurrence reopens a resolved issue.
     *
     * @param  array{action: string, version: int|string, assignee_id?: string|null, snooze_minutes?: int|string, note?: string|null}  $data
     */
    public function handle(Issue $issue, User $actor, array $data): Issue
    {
        return DB::transaction(function () use ($issue, $actor, $data): Issue {
            $project = Project::query()->lockForUpdate()->findOrFail($issue->project_id);
            Gate::forUser($actor)->authorize('update', $issue);
            $issue = Issue::query()->whereBelongsTo($project)->lockForUpdate()->findOrFail($issue->id);
            $issue->setRelation('project', $project);
            StateConflict::unlessVersion($issue->state_version, (int) $data['version'], __('This issue changed since you opened it. Refresh before trying again.'));

            $now = CarbonImmutable::now('UTC');
            $before = ['status' => $issue->status->value, 'assignee_id' => $issue->assignee_id, 'snoozed_until' => $issue->snoozed_until?->toISOString()];
            if ($data['action'] === 'assign') {
                $assigneeId = ($data['assignee_id'] ?? '') === '' ? null : (string) $data['assignee_id'];
                if ($assigneeId !== null && ! (Membership::query()->where('account_id', $project->account_id)->where('user_id', $assigneeId)->first()?->canTakeMonitoringAssignments() ?? false)) {
                    throw ValidationException::withMessages(['assignee_id' => __('Choose a member who works on Monitoring in this account.')]);
                }
                $issue->forceFill(['assignee_id' => $assigneeId]);
            } elseif ($data['action'] === 'snooze') {
                StateConflict::unless(in_array($issue->status, [IssueStatus::Open, IssueStatus::Snoozed], true), __('Reopen this issue before snoozing it.'));
                $issue->forceFill(['status' => IssueStatus::Snoozed, 'snoozed_until' => $now->addMinutes((int) ($data['snooze_minutes'] ?? 60)), 'resolved_at' => null]);
            } else {
                $status = match ($data['action']) {
                    'resolve' => IssueStatus::Resolved,
                    'reopen' => IssueStatus::Open,
                    'ignore' => IssueStatus::Ignored,
                    default => throw ValidationException::withMessages(['action' => __('Choose resolve, reopen, snooze, ignore or assign.')]),
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
}
