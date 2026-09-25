<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\ShowTraceRequest;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\EventDetails;
use Illuminate\Http\Response;

class TraceEventController extends Controller
{
    public function show(ShowTraceRequest $request, string $trace, string $event, CurrentWorkspace $currentWorkspace, EventDetails $details): Response
    {
        $record = TelemetryEvent::forWorkspace($currentWorkspace->get())->visibleTo(request()->user(), $currentWorkspace->get())
            ->with(['environment:id,application_id,name', 'environment.application:id,name'])
            ->where('trace_id', $trace)
            ->when($request->filled('environment'), fn ($query) => $query->where('environment_id', $request->integer('environment')))
            ->whereKey($event)
            ->firstOrFail();

        return response()->view('monitor::events.show', [
            ...$details->data($record),
            'backUrl' => route('monitor.traces.show', ['trace' => $trace, ...$request->safe()->only(['environment', 'page'])]),
            'backLabel' => 'Back to trace',
        ])->header('Cache-Control', 'private, no-store');
    }
}
