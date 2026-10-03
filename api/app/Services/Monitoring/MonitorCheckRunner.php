<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MonitorCheckRunner
{
    /**
     * Create a new MonitorCheckRunner instance.
     *
     * Runs monitor checks.
     *
     * @param  MonitorQueue  $queue  Locks the monitor and discards jobs for cancelled checks.
     * @param  ProbeMonitor  $probe  Performs the check.
     * @param  MonitorResults  $results  Records the result.
     */
    public function __construct(
        private readonly MonitorQueue $queue,
        private readonly ProbeMonitor $probe,
        private readonly MonitorResults $results,
    ) {}

    /**
     * Run one queued check: claims it with a lease under lock, probes outside the transaction, then records the result
     * if the claim still holds. Checks whose monitor changed are cancelled, and ones whose lease ran out are recorded
     * as missed or interrupted.
     *
     * @param  string  $id
     * @return void
     */
    public function process(string $id): void
    {
        $claimed = DB::transaction(function () use ($id): ?array {
            [$monitor, $check] = $this->lock($id);
            if ($check === null || $check->status !== 'queued') {
                return null;
            }
            if (! $this->eligible($monitor, $check)) {
                $this->cancel($check);

                return null;
            }
            if ($check->lease_until === null || $check->lease_until->lte(now('UTC'))) {
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
        DB::transaction(function () use ($id, $token, $observation): void {
            [$monitor, $check] = $this->lock($id);
            if ($check === null || $check->status !== 'running' || $check->processing_token !== $token) {
                return;
            }
            if (! $this->eligible($monitor, $check)) {
                $this->cancel($check);

                return;
            }
            $this->finish($monitor, $check, $check->lease_until === null || $check->lease_until->lte(now('UTC'))
                ? new MonitorObservation('unknown', 'worker_interrupted') : $observation);
        }, attempts: 3);
    }

    /**
     * Settle a check whose worker died (or, with `$expiredOnly`, only one whose lease ran out) as missed or
     * interrupted.
     *
     * @param  string  $id
     * @param  bool  $expiredOnly
     * @return void
     */
    public function interrupt(string $id, bool $expiredOnly = false): void
    {
        DB::transaction(function () use ($id, $expiredOnly): void {
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

    /**
     * Lock the check's monitor, then the check, in that order, so scheduling and running can't deadlock.
     *
     * @param  string  $id
     * @return array{?Monitor, ?MonitorCheck}
     */
    private function lock(string $id): array
    {
        $hint = MonitorCheck::query()->find($id);
        if ($hint === null) {
            return [null, null];
        }
        $monitor = $this->queue->lockMonitor($hint->monitor_id);

        return [$monitor, MonitorCheck::query()->lockForUpdate()->find($id)];
    }

    /**
     * Determine whether the check still applies: the monitor accepts checks and hasn't been reconfigured since the
     * check was scheduled.
     *
     * @param  Monitor|null  $monitor
     * @param  MonitorCheck  $check
     * @return bool
     *
     * @phpstan-assert-if-true Monitor $monitor
     */
    private function eligible(?Monitor $monitor, MonitorCheck $check): bool
    {
        return $this->queue->eligible($monitor) && $monitor->config_revision === $check->config_revision;
    }

    /**
     * Cancel a check whose monitor changed, discarding its job.
     *
     * @param  MonitorCheck  $check
     * @return void
     */
    private function cancel(MonitorCheck $check): void
    {
        $this->queue->discardPendingJob($check);
        $check->forceFill([
            'status' => 'cancelled', 'outcome' => 'unknown', 'reason' => 'source_changed',
            'finished_at' => now('UTC'), 'processing_token' => null, 'lease_until' => null,
        ])->save();
    }

    /**
     * Store the check's result and, unless a newer check already reported, updates the monitor's health, noting a gap
     * when intervals were skipped.
     *
     * @param  Monitor  $monitor
     * @param  MonitorCheck  $check
     * @param  MonitorObservation  $result
     * @return void
     */
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
