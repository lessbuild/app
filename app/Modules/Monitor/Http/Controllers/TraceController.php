<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\ShowTraceRequest;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\TraceTimeline;
use Illuminate\Http\Response;

class TraceController extends Controller
{
    public function show(ShowTraceRequest $request, string $trace, CurrentWorkspace $currentWorkspace, TraceTimeline $timeline): Response
    {
        $workspace = $currentWorkspace->get();
        $environmentId = $request->filled('environment') ? $request->integer('environment') : null;
        $environments = Environment::forWorkspace($workspace)->visibleTo(request()->user(), $workspace)
            ->select(['id', 'application_id', 'name'])
            ->with('application:id,name')
            ->whereHas('telemetryEvents', fn ($query) => $query->where('trace_id', $trace))
            ->orderBy('name')->orderBy('id')->get();
        abort_if($environments->isEmpty() || ($environmentId !== null && ! $environments->contains('id', $environmentId)), 404);

        $events = TelemetryEvent::forWorkspace($workspace)->visibleTo(request()->user(), $workspace)
            ->summary()
            ->with(['environment:id,application_id,name', 'environment.application:id,name'])
            ->where('trace_id', $trace)
            ->when($environmentId !== null, fn ($query) => $query->where('environment_id', $environmentId))
            ->orderBy('occurred_at')
            ->orderBy('timestamp_unix_nano')
            ->orderBy('id')
            ->paginate(200)
            ->appends($request->safe()->only('environment'));

        abort_if($events->isEmpty(), 404);

        return response()->view('monitor::traces.show', [
            'traceId' => $trace,
            'events' => $events,
            'timeline' => $timeline->build($events->getCollection()),
            'environments' => $environments,
            'environmentId' => $environmentId,
        ])->header('Cache-Control', 'private, no-store');
    }
}
