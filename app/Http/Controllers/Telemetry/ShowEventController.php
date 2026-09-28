<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\EventDetailsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowEventController
{
    /**
     * One event's details, redacted.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  TelemetryEvent  $event
     * @param  ProjectOverviewQuery  $overview
     * @param  EventDetailsQuery  $details
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, TelemetryEvent $event, ProjectOverviewQuery $overview, EventDetailsQuery $details): View
    {

        return view('telemetry.event', [
            'overview' => $overview->handle($project, $user),
            'event' => $event,
            ...$details->handle($event),
        ]);
    }
}
