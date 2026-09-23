<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\IssueStatus;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Issue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WakeSnoozedIssues
{
    public function wake(int $limit = 100): int
    {
        $now = CarbonImmutable::now('UTC');
        $issues = Issue::query()->where('status', IssueStatus::Snoozed)
            ->where('snoozed_until', '<=', $now->format('Y-m-d H:i:s.u'))->whereHas('application')
            ->select(['id', 'application_id'])->orderBy('snoozed_until')->orderBy('id')
            ->limit(max(1, min(1000, $limit)))->get();

        return $issues->filter(function (Issue $candidate) use ($now): bool {
            return DB::connection('monitor')->transaction(function () use ($candidate, $now): bool {
                $application = Application::query()->lockForUpdate()->find($candidate->application_id);

                if ($application === null) {
                    return false;
                }

                $issue = Issue::query()->whereBelongsTo($application)->lockForUpdate()->find($candidate->id);

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
