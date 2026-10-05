<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Data\Monitoring\MonitorSummary;
use App\Models\HeartbeatRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\MonitorActivityQuery;
use App\Queries\Monitoring\MonitorHistoryQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Monitoring\ObservationText;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowMonitorController
{
    /**
     * Show a monitor: its health and latest observation, connecting a heartbeat or queue, its incidents, runs and
     * checks.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Monitor  $monitor
     * @param  ProjectOverviewQuery  $overview
     * @param  MonitorHistoryQuery  $history
     * @param  MonitorActivityQuery  $activity
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Monitor $monitor, ProjectOverviewQuery $overview, MonitorHistoryQuery $history, MonitorActivityQuery $activity): JsonResponse
    {
        $record = $history->handle($monitor);
        $signals = in_array($monitor->type, ['heartbeat', 'queue'], true);
        $reason = $monitor->observation['reason'] ?? null;
        $details = $monitor->observation['details'] ?? null;

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'monitor' => [
                ...(array) MonitorSummary::from($monitor),
                'version' => $monitor->state_version,
                'archived' => $monitor->trashed(),
                'observation' => $monitor->observation === null ? null : MonitorObservation::label(is_string($reason) ? $reason : null),
                'details' => is_array($details) ? ObservationText::details($details) : [],
                'hasKey' => $monitor->type === 'heartbeat' ? $monitor->heartbeat_token_hash !== null : $monitor->queue_token_hash !== null,
                'endpoint' => ! $signals ? null : ($monitor->type === 'heartbeat' ? route('api.heartbeats.store', ['heartbeat' => $monitor->id]) : route('api.queues.snapshots.store', ['queue' => $monitor->id])),
                'workersEndpoint' => $monitor->type === 'queue' ? route('api.queues.workers.store', ['queue' => $monitor->id]) : null,
                'enabled' => $monitor->enabled,
                ...$activity->handle([$monitor])[$monitor->id],
            ],
            'queue' => $monitor->type !== 'queue' ? null : ['pending' => $record->snapshot?->pending, 'failed' => $record->snapshot?->failed, 'workers' => count($record->workers)],
            'incidents' => array_map(fn (Incident $incident): array => ['id' => $incident->id, 'title' => $incident->title, 'status' => $incident->status, 'openedAt' => $incident->opened_at->toIso8601String()], $record->incidents),
            'runs' => array_map(fn (HeartbeatRun $run): array => [
                'id' => $run->run_id, 'status' => $run->status, 'startedAt' => $run->started_at?->toIso8601String(), 'endedAt' => ($run->finished_at ?? $run->deadline_at)?->toIso8601String(),
            ], $record->runs),
            'checks' => array_map(fn (MonitorCheck $check): array => [
                'id' => $check->id, 'scheduledAt' => $check->scheduled_at->toIso8601String(), 'status' => $check->status, 'outcome' => $check->outcome,
                'reason' => MonitorObservation::label($check->reason), 'httpStatus' => $check->http_status, 'durationMs' => $check->duration_ms,
            ], $record->checks),
            'canManage' => $user->can('update', $monitor),
        ]);
    }
}
