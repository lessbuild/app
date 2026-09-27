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
    public function __invoke(#[CurrentUser] User $user, Project $project, string $event, ProjectOverviewQuery $overview, EventDetailsQuery $details): View
    {
        $record = TelemetryEvent::query()->whereIn('environment_id', $project->environments()->select('id'))
            ->with('environment')->whereKey((int) $event)->firstOrFail();

        return view('telemetry.event', [
            'overview' => $overview->handle($project, $user),
            'event' => $record,
            ...$details->handle($record),
        ]);
    }
}
