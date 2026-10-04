<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Data\Telemetry\EventRow;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\EventDetailsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowEventController
{
    /**
     * Show one event: what it was, its release, trace and issue, and its attributes and payload (redacted again).
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  TelemetryEvent  $event
     * @param  ProjectOverviewQuery  $overview
     * @param  EventDetailsQuery  $details
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, TelemetryEvent $event, ProjectOverviewQuery $overview, EventDetailsQuery $details): JsonResponse
    {
        $detail = $details->handle($event);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'event' => [...(array) EventRow::from($detail['record']), 'route' => $event->route, 'severityLabel' => __(ucfirst((string) $event->severity))],
            'release' => $detail['release'] === null ? null : ['id' => $detail['release']->id, 'version' => $detail['release']->version],
            'attributes' => $detail['attributesJson'],
            'payload' => $detail['payloadJson'],
        ]);
    }
}
