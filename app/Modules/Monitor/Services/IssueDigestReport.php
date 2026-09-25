<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class IssueDigestReport
{
    public function __construct(private readonly TelemetryRedactor $redactor) {}

    /** @return array<string, mixed> */
    public function forRecipient(Workspace $workspace, User $recipient, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $issues = Issue::forWorkspace($workspace)->visibleTo($recipient, $workspace);
        if ($workspace->roleFor($recipient) === null) {
            $issues->whereRaw('1 = 0');
        }

        $new = $this->periodQuery(clone $issues, 'first_seen_at', $from, $until)->get();
        $resolved = $this->periodQuery(clone $issues, 'resolved_at', $from, $until)->get();
        // Group by source as well as status so every contributor to a count is retained for later access checks.
        $counts = (clone $issues)->whereIn('status', ['open', 'snoozed'])
            ->select(['application_id', 'environment_id', 'status', 'severity'])
            ->selectRaw('count(*) as total')
            ->groupBy('application_id', 'environment_id', 'status', 'severity')->toBase()->get();
        $open = $counts->where('status', 'open');
        $sources = $new->toBase()->concat($resolved)->concat($counts)
            ->map(fn ($issue): array => ['application_id' => (int) $issue->application_id, 'environment_id' => $issue->environment_id === null ? null : (int) $issue->environment_id])
            ->unique(fn (array $source): string => $source['application_id'].':'.($source['environment_id'] ?? 'none'))
            ->values()->all();

        return [
            'workspace_name' => $workspace->name,
            'from' => $from->toISOString(),
            'until' => $until->toISOString(),
            'open_count' => (int) $open->sum('total'),
            'critical_open_count' => (int) $open->where('severity', 'critical')->sum('total'),
            'snoozed_count' => (int) $counts->where('status', 'snoozed')->sum('total'),
            'new_issues' => $this->issues($new, 'first_seen_at'),
            'resolved_issues' => $this->issues($resolved, 'resolved_at'),
            'issues_url' => route('monitor.issues.index'),
            'has_activity' => $sources !== [],
            'source_scope' => ['version' => 1, 'sources' => $sources],
        ];
    }

    /** @param Builder<Issue> $query
     * @return Builder<Issue>
     */
    private function periodQuery(Builder $query, string $column, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return $query->whereNotNull($column)
            ->where($column, '>=', $from->format('Y-m-d H:i:s.u'))
            ->where($column, '<', $until->format('Y-m-d H:i:s.u'))
            ->with('application:id,name')
            ->select([
                'id', 'application_id', 'environment_id', 'title', 'location', 'severity', 'occurrences',
                'first_seen_at', 'last_seen_at', 'resolved_at',
            ])
            ->orderByDesc($column)->orderByDesc('id')->limit(10);
    }

    /** @param Collection<int, Issue> $issues
     * @return list<array<string, mixed>>
     */
    private function issues(Collection $issues, string $timestampColumn): array
    {
        return $issues->map(function (Issue $issue) use ($timestampColumn): array {
            $safe = $this->redactor->redact(['title' => $issue->title, 'location' => $issue->location]);

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
