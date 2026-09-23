<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RunMonitorCheck
{
    public function __construct(
        private readonly MonitorQueue $queue,
        private readonly ProbeMonitor $probe,
        private readonly RecordMonitorResult $results,
    ) {}

    public function process(string $id): void
    {
        $claimed = DB::connection('monitor')->transaction(function () use ($id): ?array {
            [$monitor, $check] = $this->lock($id);
            if ($check === null || $check->status !== 'queued') {
                return null;
            }
            if (! $this->eligible($monitor, $check)) {
                $this->cancel($check);

                return null;
            }
            if ($check->lease_until->lte(now('UTC'))) {
                $this->finish($monitor, $check, new MonitorObservation('unknown', 'check_missed'));

                return null;
            }
            $token = (string) Str::uuid();
            $check->forceFill([
                'status' => 'running', 'processing_token' => $token, 'started_at' => now('UTC'),
                'lease_until' => now('UTC')->addSeconds(MonitorQueue::LEASE_SECONDS),
            ])->save();

            return [$monitor, $token];
        }, attempts: 3);
        if ($claimed === null) {
            return;
        }
        [$target, $token] = $claimed;
        $observation = $this->probe->probe($target);
        DB::connection('monitor')->transaction(function () use ($id, $token, $observation): void {
            [$monitor, $check] = $this->lock($id);
            if ($check === null || $check->status !== 'running' || $check->processing_token !== $token) {
                return;
            }
            if (! $this->eligible($monitor, $check)) {
                $this->cancel($check);

                return;
            }
            $this->finish($monitor, $check, $check->lease_until->lte(now('UTC'))
                ? new MonitorObservation('unknown', 'worker_interrupted') : $observation);
        }, attempts: 3);
    }

    public function interrupt(string $id, bool $expiredOnly = false): void
    {
        DB::connection('monitor')->transaction(function () use ($id, $expiredOnly): void {
            [$monitor, $check] = $this->lock($id);
            if ($check === null || ! in_array($check->status, ['queued', 'running'], true)
                || ($expiredOnly && $check->lease_until?->isFuture())) {
                return;
            }
            if (! $this->eligible($monitor, $check)) {
                $this->cancel($check);

                return;
            }
            $this->finish($monitor, $check, new MonitorObservation('unknown', $check->status === 'running' ? 'worker_interrupted' : 'check_missed'));
        }, attempts: 3);
    }

    /** @return array{?Monitor, ?MonitorCheck} */
    private function lock(string $id): array
    {
        $hint = MonitorCheck::query()->find($id);
        if ($hint === null) {
            return [null, null];
        }
        $monitor = $this->queue->lockMonitor($hint->monitor_id);

        return [$monitor, MonitorCheck::query()->lockForUpdate()->find($id)];
    }

    private function eligible(?Monitor $monitor, MonitorCheck $check): bool
    {
        return $this->queue->eligible($monitor) && $monitor->config_revision === $check->config_revision;
    }

    private function cancel(MonitorCheck $check): void
    {
        $this->queue->discardPendingJob($check);
        $check->forceFill([
            'status' => 'cancelled', 'outcome' => 'unknown', 'reason' => 'source_changed',
            'finished_at' => now('UTC'), 'processing_token' => null, 'lease_until' => null,
        ])->save();
    }

    private function finish(Monitor $monitor, MonitorCheck $check, MonitorObservation $result): void
    {
        $this->queue->discardPendingJob($check);
        $now = CarbonImmutable::now('UTC');
        $check->forceFill([
            ...$result->toArray(), 'status' => 'completed', 'finished_at' => $now,
            'evidence' => $result->evidence === [] ? null : $result->evidence,
            'processing_token' => null, 'lease_until' => null,
        ])->save();
        if ($monitor->last_scheduled_at !== null && $check->scheduled_at->lte($monitor->last_scheduled_at)) {
            return;
        }
        $gap = $check->skipped_intervals > 0 || ($monitor->last_scheduled_at !== null
            && $monitor->last_scheduled_at->diffInSeconds($check->scheduled_at) > $monitor->interval_minutes * 60 + 90);
        $monitor->forceFill(['last_scheduled_at' => $check->scheduled_at]);
        $this->results->record($monitor, $result, $now, $check->location, $gap);
    }
}
