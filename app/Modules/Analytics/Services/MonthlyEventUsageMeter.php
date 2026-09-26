<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Exceptions\MonthlyEventLimitReached;
use App\Modules\Analytics\Models\WorkspaceUsagePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Persists Analytics usage independently from retained event and batch detail. */
final class MonthlyEventUsageMeter
{
    public function lockPeriod(int|string $workspaceId, CarbonImmutable $receivedAt): WorkspaceUsagePeriod
    {
        $periodStart = $receivedAt->setTimezone('UTC')->startOfMonth();
        $timestamp = $receivedAt->setTimezone('UTC');

        DB::connection('analytics')->table('workspace_usage_periods')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'period_start' => $periodStart->toDateString(),
            'accepted_events' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return WorkspaceUsagePeriod::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('period_start', $periodStart->toDateString())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function recordAccepted(
        WorkspaceUsagePeriod $period,
        int $accepted,
        ?int $limit,
        CarbonImmutable $receivedAt,
    ): void {
        $used = (int) $period->accepted_events;
        if ($limit !== null && $accepted > $limit - $used) {
            $periodStart = CarbonImmutable::parse($period->period_start, 'UTC')->startOfMonth();

            throw new MonthlyEventLimitReached(
                used: $used,
                limit: $limit,
                periodStart: $periodStart,
                retryAt: $periodStart->addMonth(),
            );
        }

        $period->forceFill([
            'accepted_events' => $used + $accepted,
            'updated_at' => $receivedAt->setTimezone('UTC'),
        ])->save();
    }
}
