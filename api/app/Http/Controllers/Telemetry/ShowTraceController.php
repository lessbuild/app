<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Data\Telemetry\EventRow;
use App\Data\Telemetry\TraceRecord;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\LiveDeploymentQuery;
use App\Services\Deploy\DeploymentMarkers;
use App\Services\Telemetry\TraceTimeline;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowTraceController
{
    /**
     * Show a trace as a timeline of its spans and events (at most 500), and the deploy that served it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $trace
     * @param  ProjectOverviewQuery  $overview
     * @param  TraceTimeline  $timeline
     * @param  LiveDeploymentQuery  $live
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $trace, ProjectOverviewQuery $overview, TraceTimeline $timeline, LiveDeploymentQuery $live): JsonResponse
    {
        abort_unless(strlen($trace) <= 64, 404);
        $events = TelemetryEvent::query()->whereIn('environment_id', $project->environments()->select('id'))
            ->summary()->with('environment')->where('trace_id', $trace)
            ->orderBy('occurred_at')->orderBy('timestamp_unix_nano')->orderBy('id')
            ->limit(500)->get();
        abort_if($events->isEmpty(), 404);
        $first = $events->firstOrFail();
        $deployment = $live->handle($first->environment_id, $first->occurred_at);
        $built = $timeline->build($events);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'traceId' => $trace,
            'title' => $built['title'],
            'duration' => TraceRecord::formatDuration($built['duration']),
            'spans' => $built['spanCount'],
            'events' => $built['eventCount'],
            'errors' => $built['errorCount'],
            'rows' => array_map(fn (array $row): array => [
                ...(array) EventRow::from($row['record']), 'left' => $row['left'], 'width' => $row['width'], 'depth' => $row['depth'], 'notes' => array_map(fn (string $note): string => __($note), $row['notes']),
            ], $built['rows']),
            'deployment' => $deployment === null ? null : [
                'id' => $deployment->id, 'version' => $deployment->release->version, 'environment' => $deployment->environment->name,
                'deployedAt' => $deployment->deployed_at->toIso8601String(), 'buildId' => DeploymentMarkers::buildIdOf($deployment),
            ],
        ]);
    }
}
