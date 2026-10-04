<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Data\Telemetry\EventRow;
use App\Data\Telemetry\TraceRecord;
use App\Http\Requests\Telemetry\SearchEventsRequest;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\EventsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowEventsController
{
    /**
     * Search the project's telemetry events (`?q=`, `?environment=`, `?type=`, `?severity=`, `?range=`, `?sort=`,
     * `?trace=`, `?page=`), fifty at a time.
     *
     * @param  SearchEventsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  EventsQuery  $search
     * @return JsonResponse
     */
    public function __invoke(SearchEventsRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, EventsQuery $search): JsonResponse
    {
        $filters = $request->filters();
        $window = $search->window($filters);
        $events = $search->ordered($search->handle($project, $filters, $window), (string) $filters['sort'])
            ->summary()->with('environment')
            ->paginate(50, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'events' => collect($events->items())->map(fn (TelemetryEvent $event): EventRow => EventRow::from(new TraceRecord($event)))->values(),
            'page' => $events->currentPage(),
            'lastPage' => $events->lastPage(),
            'filters' => $filters,
            'types' => array_map(fn (string $label): string => __($label), SearchEventsRequest::TYPES),
            'severities' => array_map(fn (string $label): string => __($label), SearchEventsRequest::SEVERITIES),
            'ranges' => array_map(fn (string $label): string => __($label), array_diff_key(SearchEventsRequest::RANGES, ['custom' => true])),
            'sorts' => array_map(fn (string $label): string => __($label), SearchEventsRequest::SORTS),
        ]);
    }
}
