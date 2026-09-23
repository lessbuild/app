<?php

namespace App\Modules\Monitor\Services;

use App\Core\Data\Billing\ProductPlanResolution;
use App\Modules\Monitor\Models\TelemetryUsageEntry;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;

final class WorkspaceUsage
{
    public const WARNING_THRESHOLD = 80;

    public const LIMIT_THRESHOLD = 100;

    /** @var array<string, ProductPlanResolution> */
    private array $resolvedPlans = [];

    public function __construct(private readonly MonitorPlanAuthority $authority) {}

    public function eventsThisMonth(Workspace $workspace): int
    {
        return $this->eventsThrough($workspace, CarbonImmutable::now('UTC'));
    }

    public function eventLimit(Workspace $workspace): int
    {
        return $this->eventLimitState($workspace)['limit'];
    }

    /** @return array{available: bool, limit: int} */
    private function eventLimitState(Workspace $workspace): array
    {
        if ($this->authority->usesCore()) {
            $plan = $this->resolvedPlan($workspace);

            if (! $plan->available || ! $plan->hasLimit('events_per_month')) {
                return ['available' => false, 'limit' => 0];
            }

            return [
                'available' => true,
                'limit' => $plan->limit('events_per_month') ?? PHP_INT_MAX,
            ];
        }

        $plan = config('monitor.beacon.plans.'.$workspace->plan, config('monitor.beacon.plans.free'));

        return ['available' => true, 'limit' => max(0, (int) ($plan['event_limit'] ?? 0))];
    }

    public function remainingEvents(Workspace $workspace): int
    {
        return max(0, $this->eventLimit($workspace) - $this->eventsThisMonth($workspace));
    }

    /**
     * @return array{
     *     period_start: CarbonImmutable,
     *     period_end: CarbonImmutable,
     *     event_count: int,
     *     event_limit: int,
     *     remaining: int,
     *     percentage: int,
     *     state: 'healthy'|'warning'|'limit'|'unavailable',
     *     plan_available: bool,
     *     crossed_thresholds: list<int>
     * }
     */
    public function summary(Workspace $workspace, ?CarbonImmutable $at = null): array
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc();
        $periodStart = $at->startOfMonth();
        $eventCount = $this->eventsThrough($workspace, $at);
        $limitState = $this->eventLimitState($workspace);
        $eventLimit = $limitState['limit'];
        $planAvailable = $limitState['available'];
        $percentage = $eventLimit > 0 ? min(100, (int) round(($eventCount / $eventLimit) * 100)) : 0;
        $crossedThresholds = $eventLimit > 0
            ? array_values(array_filter($this->alertThresholds(), fn (int $threshold): bool => $eventCount >= (int) ceil($eventLimit * $threshold / 100)))
            : [];
        $state = ! $planAvailable
            ? 'unavailable'
            : ($eventLimit === 0 || $eventCount >= $eventLimit
            ? 'limit'
            : (in_array(self::WARNING_THRESHOLD, $crossedThresholds, true) ? 'warning' : 'healthy'));

        return [
            'period_start' => $periodStart,
            'period_end' => $periodStart->addMonth(),
            'event_count' => $eventCount,
            'event_limit' => $eventLimit,
            'remaining' => max(0, $eventLimit - $eventCount),
            'percentage' => $percentage,
            'state' => $state,
            'plan_available' => $planAvailable,
            'crossed_thresholds' => $crossedThresholds,
        ];
    }

    /** @return list<int> */
    public function alertThresholds(): array
    {
        $configured = config('monitor.beacon.usage_alerts.thresholds', [self::WARNING_THRESHOLD, self::LIMIT_THRESHOLD]);
        if (! is_array($configured)) {
            return [self::WARNING_THRESHOLD, self::LIMIT_THRESHOLD];
        }

        $thresholds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $threshold): int => is_numeric($threshold) ? (int) $threshold : 0,
            $configured,
        ), static fn (int $threshold): bool => $threshold > 0 && $threshold <= 100)));
        sort($thresholds);

        return $thresholds === [] ? [self::WARNING_THRESHOLD, self::LIMIT_THRESHOLD] : $thresholds;
    }

    public function canAccept(Workspace $workspace, int $eventCount, CarbonImmutable $receivedAt): bool
    {
        if ($eventCount < 1) {
            return true;
        }

        return $this->eventsThrough($workspace, $receivedAt) + $eventCount <= $this->eventLimit($workspace);
    }

    private function resolvedPlan(Workspace $workspace): ProductPlanResolution
    {
        $key = (string) $workspace->getKey();

        return $this->resolvedPlans[$key] ??= $this->authority->resolve($workspace);
    }

    private function eventsThrough(Workspace $workspace, CarbonImmutable $until): int
    {
        $monthStart = $until->startOfMonth();

        return (int) TelemetryUsageEntry::query()->whereBelongsTo($workspace)
            ->whereBetween('received_at', [
                $monthStart->format('Y-m-d H:i:s.u'),
                $until->format('Y-m-d H:i:s.u'),
            ])->sum('event_count');
    }
}
