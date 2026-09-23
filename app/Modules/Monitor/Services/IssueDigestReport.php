<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class IssueDigestReport
{
    public function __construct(private readonly TelemetryRedactor $redactor) {}

    /**
     * @return array{
     *     workspace_name: string,
     *     from: string,
     *     until: string,
     *     open_count: int,
     *     critical_open_count: int,
     *     snoozed_count: int,
     *     new_issues: list<array{title: string, location: string|null, application: string, severity: string, occurrences: int, seen_at: string, url: string}>,
     *     resolved_issues: list<array{title: string, location: string|null, application: string, severity: string, occurrences: int, seen_at: string, url: string}>,
     *     issues_url: string,
     *     has_activity: bool,
     * }
     */
    public function forWorkspace(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $newIssues = $this->issues(
            $this->periodQuery($workspace, 'first_seen_at', $from, $until),
            'first_seen_at',
        );
        $resolvedIssues = $this->issues(
            $this->periodQuery($workspace, 'resolved_at', $from, $until),
            'resolved_at',
        );
        $statusCounts = Issue::forWorkspace($workspace)
            ->whereIn('status', ['open', 'snoozed'])
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $criticalOpenCount = Issue::forWorkspace($workspace)
            ->where('status', 'open')
            ->where('severity', 'critical')
            ->count();

        return [
            'workspace_name' => $workspace->name,
            'from' => $from->toISOString(),
            'until' => $until->toISOString(),
            'open_count' => (int) ($statusCounts->get('open') ?? 0),
            'critical_open_count' => (int) $criticalOpenCount,
            'snoozed_count' => (int) ($statusCounts->get('snoozed') ?? 0),
            'new_issues' => $newIssues,
            'resolved_issues' => $resolvedIssues,
            'issues_url' => route('monitor.issues.index'),
            'has_activity' => $newIssues !== [] || $resolvedIssues !== [] || $statusCounts->isNotEmpty(),
        ];
    }

    /** @return Builder<Issue> */
    private function periodQuery(Workspace $workspace, string $column, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return Issue::forWorkspace($workspace)
            ->whereNotNull($column)
            ->where($column, '>=', $from->format('Y-m-d H:i:s.u'))
            ->where($column, '<', $until->format('Y-m-d H:i:s.u'))
            ->with('application:id,name')
            ->select([
                'id', 'application_id', 'title', 'location', 'severity', 'occurrences',
                'first_seen_at', 'last_seen_at', 'resolved_at',
            ])
            ->orderByDesc($column)->orderByDesc('id')->limit(10);
    }

    /**
     * @param  Builder<Issue>  $query
     * @return list<array{title: string, location: string|null, application: string, severity: string, occurrences: int, seen_at: string, url: string}>
     */
    private function issues(Builder $query, string $timestampColumn): array
    {
        return $query->get()->map(function (Issue $issue) use ($timestampColumn): array {
            $safe = $this->redactor->redact([
                'title' => $issue->title,
                'location' => $issue->location,
            ]);

            return [
                'title' => (string) $safe['title'],
                'location' => $safe['location'] === null ? null : (string) $safe['location'],
                'application' => $issue->application->name,
                'severity' => $issue->severity,
                'occurrences' => $issue->occurrences,
                'seen_at' => $issue->{$timestampColumn}->utc()->format('Y-m-d H:i:s').' UTC',
                'url' => route('monitor.issues.show', $issue),
            ];
        })->all();
    }
}
