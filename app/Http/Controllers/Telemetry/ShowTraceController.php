<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Telemetry\TraceTimeline;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** One trace's spans and events on a timeline. */
final class ShowTraceController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $trace, ProjectOverviewQuery $overview, TraceTimeline $timeline): View
    {
        abort_unless(strlen($trace) <= 64, 404);
        $events = TelemetryEvent::query()->whereIn('environment_id', $project->environments()->select('id'))
            ->summary()->with('environment')->where('trace_id', $trace)
            ->orderBy('occurred_at')->orderBy('timestamp_unix_nano')->orderBy('id')
            ->limit(500)->get();
        abort_if($events->isEmpty(), 404);

        return view('telemetry.trace', [
            'overview' => $overview->handle($project, $user),
            'traceId' => $trace,
            'timeline' => $timeline->build($events),
        ]);
    }
}
