<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Data\Telemetry\TraceRecord;
use App\Http\Requests\Telemetry\SearchEventsRequest;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\EventsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Search every request, query, job, log, exception and metric the project's environments sent. */
final class ShowEventsController
{
    public function __invoke(SearchEventsRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, EventsQuery $search): View
    {
        $filters = $request->filters();
        $window = $search->window($filters);
        $events = $search->ordered($search->handle($project, $filters, $window), (string) $filters['sort'])
            ->summary()->with('environment')
            ->paginate(50, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($request->safe()->except('page'));

        return view('telemetry.events', [
            'overview' => $overview->handle($project, $user),
            'events' => $events,
            'records' => $events->getCollection()->map(fn (TelemetryEvent $event): TraceRecord => new TraceRecord($event)),
            'filters' => $filters,
            'window' => $window,
            'typeOptions' => SearchEventsRequest::TYPES,
            'severityOptions' => SearchEventsRequest::SEVERITIES,
            'rangeOptions' => SearchEventsRequest::RANGES,
            'sortOptions' => SearchEventsRequest::SORTS,
        ]);
    }
}
