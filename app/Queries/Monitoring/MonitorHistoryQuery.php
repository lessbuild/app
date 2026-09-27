<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Data\Monitoring\MonitorHistory;
use App\Models\Monitor;

final class MonitorHistoryQuery
{
    public function handle(Monitor $monitor): MonitorHistory
    {
        return new MonitorHistory(
            checks: array_values($monitor->checks()->whereIn('status', ['completed', 'running', 'queued'])
                ->latest('scheduled_at')->latest('id')->limit(30)->get()->all()),
            incidents: array_values($monitor->incidents()->latest('opened_at')->latest('id')->limit(10)->get()->all()),
            runs: $monitor->type === 'heartbeat'
                ? array_values($monitor->heartbeatRuns()->latest('id')->limit(20)->get()->all())
                : [],
            snapshot: $monitor->type === 'queue' && $monitor->queue_snapshot_id !== null
                ? $monitor->queueSnapshots()->find($monitor->queue_snapshot_id)
                : null,
            workers: $monitor->type === 'queue'
                ? array_values($monitor->queueWorkers()->where('config_revision', $monitor->config_revision)
                    ->where('last_seen_at', '>', now('UTC')->subDay()->format('Y-m-d H:i:s.u'))
                    ->latest('last_seen_at')->limit(50)->get()->all())
                : [],
        );
    }
}
