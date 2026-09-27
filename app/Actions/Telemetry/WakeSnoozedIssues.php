<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WakeSnoozedIssues
{
    /** Reopen snoozed issues whose snooze has ended. */
    public function handle(int $limit = 100): int
    {
        $now = CarbonImmutable::now('UTC');
        $issues = Issue::query()->where('status', IssueStatus::Snoozed)
            ->where('snoozed_until', '<=', $now->format('Y-m-d H:i:s.u'))->whereHas('project')
            ->select(['id', 'project_id'])->orderBy('snoozed_until')->orderBy('id')
            ->limit(max(1, min(1000, $limit)))->get();

        return $issues->filter(function (Issue $candidate) use ($now): bool {
            return DB::transaction(function () use ($candidate, $now): bool {
                $project = Project::query()->lockForUpdate()->find($candidate->project_id);

                if ($project === null) {
                    return false;
                }

                $issue = Issue::query()->whereBelongsTo($project)->lockForUpdate()->find($candidate->id);

                if ($issue === null || $issue->status !== IssueStatus::Snoozed || $issue->snoozed_until === null || $issue->snoozed_until->greaterThan($now)) {
                    return false;
                }

                $issue->forceFill(['status' => IssueStatus::Open, 'snoozed_until' => null, 'state_version' => $issue->state_version + 1])->save();
                $issue->activities()->create(['action' => 'snooze_expired', 'metadata' => ['status' => 'open']]);

                return true;
            }, attempts: 3);
        })->count();
    }
}
