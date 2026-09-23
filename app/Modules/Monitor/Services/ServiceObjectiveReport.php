<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ServiceObjectiveReport
{
    /**
     * @return array{
     *     from: CarbonImmutable,
     *     until: CarbonImmutable,
     *     total: int,
     *     observed: int,
     *     good: int,
     *     bad: int,
     *     unknown: int,
     *     compliance: float|null,
     *     target: float,
     *     budget_remaining: float|null,
     *     burn_rate: float|null,
     *     status: string,
     *     first_measured_at: CarbonImmutable|null,
     *     last_measured_at: CarbonImmutable|null
     * }
     */
    public function forObjective(ServiceLevelObjective $objective, ?CarbonImmutable $until = null): array
    {
        $until ??= CarbonImmutable::now('UTC');

        return $this->forPeriod($objective, $until->subDays($objective->window_days), $until);
    }

    /**
     * @return array{
     *     from: CarbonImmutable,
     *     until: CarbonImmutable,
     *     total: int,
     *     observed: int,
     *     good: int,
     *     bad: int,
     *     unknown: int,
     *     compliance: float|null,
     *     target: float,
     *     budget_remaining: float|null,
     *     burn_rate: float|null,
     *     status: string,
     *     first_measured_at: CarbonImmutable|null,
     *     last_measured_at: CarbonImmutable|null
     * }
     */
    public function forPeriod(ServiceLevelObjective $objective, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $query = TelemetryEvent::query()
            ->where('environment_id', $objective->environment_id)
            ->where('type', 'request')
            ->where('occurred_at', '>=', $this->boundary($from))
            ->where('occurred_at', '<', $this->boundary($until))
            ->when(filled($objective->service), fn (Builder $query): Builder => $query->where('service', $objective->service))
            ->when(filled($objective->route), fn (Builder $query): Builder => $query->where('route', $objective->route));
        $total = (int) (clone $query)->count();
        $first = (clone $query)->min('occurred_at');
        $last = (clone $query)->max('occurred_at');

        if ($objective->isLatency()) {
            $observedQuery = (clone $query)->whereNotNull('duration_ms')->where('duration_ms', '>=', 0);
            $observed = (int) $observedQuery->count();
            $good = (int) (clone $observedQuery)->where('duration_ms', '<=', $objective->latency_threshold_ms)->count();
        } else {
            $observedQuery = (clone $query)->whereNotNull('status_code');
            $observed = (int) $observedQuery->count();
            $good = (int) (clone $observedQuery)->whereBetween('status_code', [$objective->status_min, $objective->status_max])->count();
        }
        $bad = max(0, $observed - $good);
        $unknown = max(0, $total - $observed);
        $compliance = $observed > 0 ? round($good / $observed * 100, 3) : null;
        $allowedBad = $observed * (1 - $objective->target / 100);
        $budgetRemaining = $allowedBad > 0 ? round(($allowedBad - $bad) / $allowedBad * 100, 2) : null;
        $burnRate = $allowedBad > 0 ? round($bad / $allowedBad, 3) : null;
        $status = $observed === 0 ? 'no_data' : ($budgetRemaining !== null && $budgetRemaining < 0 ? 'exhausted' : ($budgetRemaining !== null && $budgetRemaining < 25 ? 'warning' : 'healthy'));

        return [
            'from' => $from,
            'until' => $until,
            'total' => $total,
            'observed' => $observed,
            'good' => $good,
            'bad' => $bad,
            'unknown' => $unknown,
            'compliance' => $compliance,
            'target' => (float) $objective->target,
            'budget_remaining' => $budgetRemaining,
            'burn_rate' => $burnRate,
            'status' => $status,
            'first_measured_at' => $this->timestamp($first),
            'last_measured_at' => $this->timestamp($last),
        ];
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    private function boundary(CarbonImmutable $time): string
    {
        return $time->format($time->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }
}
