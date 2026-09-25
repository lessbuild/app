<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReleaseMetrics
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
    public function events(Workspace $workspace, Release $release, ?int $environmentId, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return TelemetryEvent::query()->where('release_id', $release->id)
            ->whereIn('environment_id', Environment::forWorkspace($workspace)->where('application_id', $release->application_id)->select('id'))
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
    public function aroundDeployment(Workspace $workspace, Deployment $deployment, int $minutes): array
    {
        return $this->compareAroundDeployment($workspace, $deployment, $minutes * 60, $minutes);
    }

    /** @return array{before: array<string, int|float|null>|null, after: array<string, int|float|null>|null, from: CarbonImmutable, deployedAt: CarbonImmutable, until: CarbonImmutable, seconds: int, requestedMinutes: int} */
    public function aroundDeploymentWindow(Workspace $workspace, Deployment $deployment, int $requestedSeconds, int $requestedMinutes): array
    {
        return $this->compareAroundDeployment($workspace, $deployment, $requestedSeconds, $requestedMinutes);
    }

    /** @return array{before: array<string, int|float|null>|null, after: array<string, int|float|null>|null, from: CarbonImmutable, deployedAt: CarbonImmutable, until: CarbonImmutable, seconds: int, requestedMinutes: int} */
    private function compareAroundDeployment(Workspace $workspace, Deployment $deployment, int $requestedSeconds, int $requestedMinutes): array
    {
        $at = $deployment->deployed_at;
        $seconds = (int) max(0, min($requestedSeconds, $at->diffInSeconds(CarbonImmutable::now('UTC'), false)));
        $from = $at->subSeconds($seconds);
        $until = $at->addSeconds($seconds);
        $query = TelemetryEvent::query()
            ->whereIn('environment_id', Environment::forWorkspace($workspace)->whereKey($deployment->environment_id)->select('id'))
            ->whereIn('release_id', Release::forWorkspace($workspace)
                ->where('application_id', $deployment->release->application_id)
                ->where('service_hash', $deployment->release->service_hash)->select('id'));
        $before = $seconds > 0 ? $this->summarize((clone $query)->where('occurred_at', '>=', $this->boundary($from))->where('occurred_at', '<', $this->boundary($at))) : null;
        $after = $seconds > 0 ? $this->summarize((clone $query)->where('occurred_at', '>=', $this->boundary($at))->where('occurred_at', '<', $this->boundary($until))) : null;

        return ['before' => $before, 'after' => $after, 'from' => $from, 'deployedAt' => $at, 'until' => $until, 'seconds' => $seconds, 'requestedMinutes' => $requestedMinutes];
    }

    /** @return Collection<int, Deployment> */
    public function otherDeploymentsInWindow(Workspace $workspace, Deployment $deployment, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return Deployment::query()->forWorkspace($workspace)
            ->where('environment_id', $deployment->environment_id)
            ->where('id', '!=', $deployment->getKey())
            ->where('deployed_at', '>=', $this->boundary($from))
            ->where('deployed_at', '<', $this->boundary($until))
            ->whereHas('release', fn (Builder $release): Builder => $release
                ->where('application_id', $deployment->release->application_id)
                ->where('service_hash', $deployment->release->service_hash))
            ->with('release:id,application_id,service,service_namespace,version,service_hash')
            ->orderBy('deployed_at')->orderBy('id')->limit(20)->get();
    }

    /** @return Collection<int, Incident> */
    public function incidentsOverlappingWindow(Workspace $workspace, Deployment $deployment, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        if ($from >= $until) {
            return collect();
        }

        $environmentId = $deployment->environment_id;

        return Incident::query()->forWorkspace($workspace)
            ->where(function (Builder $query) use ($environmentId): void {
                $query->whereIn('monitor_id', Monitor::withTrashed()->where('environment_id', $environmentId)->select('id'))
                    ->orWhereIn('alert_rule_id', AlertRule::withTrashed()->where('environment_id', $environmentId)->select('id'));
            })
            ->where('opened_at', '<', $this->boundary($until))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('resolved_at')
                ->orWhere('resolved_at', '>=', $this->boundary($from)))
            ->orderBy('opened_at')->orderBy('id')->limit(20)
            ->get(['id', 'title', 'status', 'opened_at', 'resolved_at']);
    }

    /** Legacy second-only timestamps must be compared on the same boundary. */
    private function boundary(CarbonImmutable $time): string
    {
        return $time->format($time->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }
}
