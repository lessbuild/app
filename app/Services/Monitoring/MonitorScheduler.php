<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class MonitorScheduler
{
    /**
     * Schedules monitor checks.
     *
     * @param  MonitorQueue  $queue  Locks monitors and queues their checks.
     * @param  MonitorCheckRunner  $runner  Settles checks whose workers died.
     * @param  HeartbeatEvaluator  $heartbeats  Evaluates heartbeat monitors on schedule.
     * @param  QueueMonitorEvaluator  $queues  Evaluates queue monitors on schedule.
     */
    public function __construct(
        private readonly MonitorQueue $queue,
        private readonly MonitorCheckRunner $runner,
        private readonly HeartbeatEvaluator $heartbeats,
        private readonly QueueMonitorEvaluator $queues,
    ) {}

    /**
     * Schedules due checks for up to `$limit` enabled monitors in projects with Monitoring on (after settling expired
     * checks). Heartbeat and queue monitors are evaluated in place; others get a check queued with a lease, and
     * intervals skipped while the scheduler was behind are counted.
     *
     * @param  int  $limit
     * @return int
     */
    public function schedule(int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $this->recover($limit);
        $now = CarbonImmutable::now('UTC');
        $ids = Monitor::query()->where('enabled', true)->where('next_check_at', '<=', $now->format('Y-m-d H:i:s.u'))
            // A project with Monitoring turned off keeps its monitors but doesn't run them.
            ->whereHas('environment.project.enabledServices', fn (Builder $query): Builder => $query->where('service', 'monitoring'))
            ->whereDoesntHave('checks', fn (Builder $query): Builder => $query->whereIn('status', ['queued', 'running']))
            ->orderBy('next_check_at')->orderBy('id')->limit($limit)->pluck('id');

        return $ids->filter(fn (int $id): bool => DB::transaction(function () use ($id, $now): bool {
            $monitor = $this->queue->lockMonitor($id);
            if (! $this->queue->eligible($monitor) || $monitor->next_check_at === null || $monitor->next_check_at->gt($now)
                || $monitor->checks()->whereIn('status', ['queued', 'running'])->exists()) {
                return false;
            }
            if ($monitor->type === 'heartbeat') {
                $this->heartbeats->evaluate($monitor, $now);

                return true;
            }
            if ($monitor->type === 'queue') {
                $this->queues->evaluate($monitor, $now);

                return true;
            }
            $skipped = max(0, (int) floor($monitor->next_check_at->diffInSeconds($now) / ($monitor->interval_minutes * 60)));
            $check = new MonitorCheck;
            $check->forceFill([
                'monitor_id' => $monitor->id, 'config_revision' => $monitor->config_revision,
                'scheduled_at' => $now, 'location' => mb_substr((string) config('monitoring.monitors.location', 'This server'), 0, 80),
                'status' => 'queued', 'skipped_intervals' => $skipped,
                'lease_until' => $now->addSeconds(MonitorQueue::LEASE_SECONDS),
            ])->save();
            $monitor->forceFill(['next_check_at' => $now->startOfMinute()->addMinutes($monitor->interval_minutes)])->save();
            $this->queue->dispatch($check);

            return true;
        }, attempts: 3))->count();
    }

    /**
     * Settles checks whose lease ran out, so a dead worker's check doesn't block its monitor. Returns how many.
     *
     * @param  int  $limit
     * @return int
     */
    public function recover(int $limit = 100): int
    {
        $ids = MonitorCheck::query()->whereIn('status', ['queued', 'running'])
            ->where('lease_until', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orderBy('lease_until')->orderBy('id')
            ->limit(max(1, min(1000, $limit)))->pluck('id');

        foreach ($ids as $id) {
            $this->runner->interrupt($id, expiredOnly: true);
        }

        return $ids->count();
    }
}
