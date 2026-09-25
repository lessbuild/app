<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ScheduleMonitorChecks
{
    public function __construct(
        private readonly MonitorQueue $queue,
        private readonly RunMonitorCheck $runner,
        private readonly RecordHeartbeat $heartbeats,
        private readonly EvaluateQueueMonitor $queues,
    ) {}

    public function schedule(int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $this->recover($limit);
        $now = CarbonImmutable::now('UTC');
        $ids = Monitor::query()->where('enabled', true)->where('next_check_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->whereHas('environment', fn (Builder $query): Builder => $query->where('status', 'active')->whereHas('application'))
            ->whereDoesntHave('checks', fn (Builder $query): Builder => $query->whereIn('status', ['queued', 'running']))
            ->orderBy('next_check_at')->orderBy('id')->limit($limit)->pluck('id');

        return $ids->filter(fn (int $id): bool => DB::connection('monitor')->transaction(function () use ($id, $now): bool {
            $monitor = $this->queue->lockMonitor($id);
            if (! $this->queue->eligible($monitor) || $monitor->next_check_at === null || $monitor->next_check_at->gt($now)
                || $monitor->checks()->whereIn('status', ['queued', 'running'])->exists()) {
                return false;
            }
            if (MonitorDeletionFence::lockWorkspace($monitor->environment->application->workspace_id)) {
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
                'scheduled_at' => $now, 'location' => mb_substr((string) config('monitor.beacon.monitors.location', 'This server'), 0, 80),
                'status' => 'queued', 'skipped_intervals' => $skipped,
                'lease_until' => $now->addSeconds(MonitorQueue::LEASE_SECONDS),
            ])->save();
            $monitor->forceFill(['next_check_at' => $now->startOfMinute()->addMinutes($monitor->interval_minutes)])->save();
            $this->queue->dispatch($check);

            return true;
        }, attempts: 3))->count();
    }

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
