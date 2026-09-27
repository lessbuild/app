<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ReleaseMetricsQuery
{
    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function window(string $range): array
    {
        $until = CarbonImmutable::now('UTC');

        return [$until->subDays(match ($range) {
            '7d' => 7, '30d' => 30, default => 1
        }), $until];
    }

    /** @return Builder<TelemetryEvent> */
    public function events(Project $project, Release $release, ?string $environmentId, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return TelemetryEvent::query()->where('release_id', $release->id)
            ->whereIn('environment_id', Environment::query()->where('project_id', $project->id)->select('id'))
            ->when($environmentId !== null, fn (Builder $query): Builder => $query->where('environment_id', $environmentId))
            ->where('occurred_at', '>=', $this->boundary($from))
            ->where('occurred_at', '<=', $until->format('Y-m-d H:i:s.u'));
    }

    /** @param Builder<TelemetryEvent> $query
     * @return array{events: int, requests: int, timed: int, failed: int, averageDuration: float|null, errorRate: float|null, exceptions: int, issues: int}
     */
    public function summarize(Builder $query): array
    {
        $row = (clone $query)->toBase()
            ->selectRaw('COUNT(*) AS events')
            ->selectRaw("COUNT(CASE WHEN type = 'request' THEN 1 END) AS requests")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND duration_ms >= 0 THEN 1 END) AS timed")
            ->selectRaw("AVG(CASE WHEN type = 'request' AND duration_ms >= 0 THEN duration_ms END) AS duration")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND (status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical')) THEN 1 END) AS failed")
            ->selectRaw("COUNT(CASE WHEN type = 'exception' THEN 1 END) AS exceptions")
            ->selectRaw("COUNT(DISTINCT CASE WHEN type = 'exception' THEN issue_id END) AS issues")
            ->first();
        if ($row === null) {
            return ['events' => 0, 'requests' => 0, 'timed' => 0, 'failed' => 0, 'averageDuration' => null, 'errorRate' => null, 'exceptions' => 0, 'issues' => 0];
        }

        return [
            'events' => (int) $row->events, 'requests' => (int) $row->requests, 'timed' => (int) $row->timed, 'failed' => (int) $row->failed,
            'averageDuration' => $row->duration !== null ? (float) $row->duration : null,
            'errorRate' => $row->requests > 0 ? $row->failed / $row->requests * 100 : null,
            'exceptions' => (int) $row->exceptions, 'issues' => (int) $row->issues,
        ];
    }

    /** Equal observed windows, narrowed for deployments less than a full window old.
     * @return array{before: array<string, int|float|null>|null, after: array<string, int|float|null>|null, from: CarbonImmutable, deployedAt: CarbonImmutable, until: CarbonImmutable, seconds: int, requestedMinutes: int}
     */
    public function aroundDeployment(Project $project, Deployment $deployment, int $minutes): array
    {
        $at = $deployment->deployed_at;
        $seconds = (int) max(0, min($minutes * 60, $at->diffInSeconds(CarbonImmutable::now('UTC'), false)));
        $from = $at->subSeconds($seconds);
        $until = $at->addSeconds($seconds);
        $query = TelemetryEvent::query()
            ->whereIn('environment_id', Environment::query()->where('project_id', $project->id)->whereKey($deployment->environment_id)->select('id'))
            ->whereIn('release_id', Release::query()->whereBelongsTo($project)
                ->where('project_id', $deployment->release->project_id)
                ->where('service_hash', $deployment->release->service_hash)->select('id'));
        $before = $seconds > 0 ? $this->summarize((clone $query)->where('occurred_at', '>=', $this->boundary($from))->where('occurred_at', '<', $this->boundary($at))) : null;
        $after = $seconds > 0 ? $this->summarize((clone $query)->where('occurred_at', '>=', $this->boundary($at))->where('occurred_at', '<', $this->boundary($until))) : null;

        return ['before' => $before, 'after' => $after, 'from' => $from, 'deployedAt' => $at, 'until' => $until, 'seconds' => $seconds, 'requestedMinutes' => $minutes];
    }

    /** Legacy second-only timestamps must be compared on the same boundary. */
    private function boundary(CarbonImmutable $time): string
    {
        return $time->format($time->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }
}
