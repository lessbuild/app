<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Data\Telemetry\TraceRecord;
use App\Modules\Monitor\Http\Requests\SearchEventsRequest;
use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\EventDetails;
use App\Modules\Monitor\Services\Telemetry\SearchEvents;
use Illuminate\Http\Response;

class EventController extends Controller
{
    public function index(SearchEventsRequest $request, CurrentWorkspace $currentWorkspace, SearchEvents $search): Response
    {
        $workspace = $currentWorkspace->get();
        $filters = $request->filters();
        $applications = $workspace->applications()->select(['id', 'name'])
            ->with(['environments' => fn ($query) => $query->select(['id', 'application_id', 'name'])->orderBy('name')->orderBy('id')])
            ->orderBy('name')->orderBy('id')->get();
        if (isset($filters['application'])) {
            abort_unless($applications->contains('id', (int) $filters['application']), 404);
        }
        $environments = $applications
            ->filter(fn ($application): bool => ! isset($filters['application']) || $application->id === (int) $filters['application'])
            ->flatMap(fn ($application) => $application->environments->each(fn ($environment) => $environment->setRelation('application', $application)));
        if (isset($filters['environment'])) {
            abort_unless($environments->contains('id', (int) $filters['environment']), 404);
        }

        $window = $search->window($filters);
        $release = isset($filters['release']) ? Release::forWorkspace($workspace)
            ->when(isset($filters['application']), fn ($query) => $query->where('application_id', $filters['application']))
            ->when(isset($filters['environment']), fn ($query) => $query->where('application_id', $environments->firstWhere('id', (int) $filters['environment'])->application_id))
            ->findOrFail($filters['release']) : null;
        $events = $search->ordered($search->query($workspace, $filters, $window), $filters['sort'])
            ->summary()
            ->with(['environment:id,application_id,name', 'environment.application:id,name'])
            ->paginate(50, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($request->safe()->except('page'));
        $records = $events->getCollection()->map(fn (TelemetryEvent $event): TraceRecord => new TraceRecord($event));

        return response()->view('monitor::events.index', [
            'events' => $events,
            'release' => $release,
            'records' => $records,
            'filters' => $filters,
            'applications' => $applications,
            'environments' => $environments,
            'window' => $window,
            'typeOptions' => SearchEventsRequest::TYPES,
            'severityOptions' => SearchEventsRequest::SEVERITIES,
            'rangeOptions' => SearchEventsRequest::RANGES,
            'sortOptions' => SearchEventsRequest::SORTS,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(SearchEventsRequest $request, string $event, CurrentWorkspace $currentWorkspace, EventDetails $details): Response
    {
        $record = TelemetryEvent::forWorkspace($currentWorkspace->get())
            ->with(['environment:id,application_id,name', 'environment.application:id,name'])
            ->whereKey($event)->firstOrFail();

        return response()->view('monitor::events.show', [
            ...$details->data($record),
            'backUrl' => route('monitor.events.index', $request->validated()),
            'backLabel' => 'Back to events',
        ])->header('Cache-Control', 'private, no-store');
    }
}
