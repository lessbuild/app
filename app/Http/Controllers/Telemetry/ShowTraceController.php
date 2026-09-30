<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\LiveDeploymentQuery;
use App\Services\Deploy\DeploymentMarkers;
use App\Services\Telemetry\TraceTimeline;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** One trace's spans and events on a timeline. */
final class ShowTraceController
{
    /**
     * Show a trace's waterfall, up to 500 events, with the deploy that was live when it started. Unknown traces are a
     * 404.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $trace
     * @param  ProjectOverviewQuery  $overview
     * @param  TraceTimeline  $timeline
     * @param  LiveDeploymentQuery  $live
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $trace, ProjectOverviewQuery $overview, TraceTimeline $timeline, LiveDeploymentQuery $live): View
    {
        abort_unless(strlen($trace) <= 64, 404);
        $events = TelemetryEvent::query()->whereIn('environment_id', $project->environments()->select('id'))
            ->summary()->with('environment')->where('trace_id', $trace)
            ->orderBy('occurred_at')->orderBy('timestamp_unix_nano')->orderBy('id')
            ->limit(500)->get();
        abort_if($events->isEmpty(), 404);
        $first = $events->first();
        $deployment = $live->handle($first->environment_id, $first->occurred_at);

        return view('telemetry.trace', [
            'overview' => $overview->handle($project, $user),
            'traceId' => $trace,
            'timeline' => $timeline->build($events),
            'deployment' => $deployment,
            'buildId' => $deployment === null ? null : DeploymentMarkers::buildIdOf($deployment),
        ]);
    }
}
