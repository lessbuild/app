<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateIncident;
use App\Http\Requests\Monitoring\IncidentRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateIncidentController
{
    public function __invoke(IncidentRequest $request, #[CurrentUser] User $user, Project $project, string $incident, ProjectIncidentsQuery $incidents, UpdateIncident $update): RedirectResponse
    {
        $target = $incidents->find($project, $incident);
        $update->handle($target, $user, $request->details());

        return to_route('monitoring.incidents.show', [$project, $target->id])->with('status', __('Incident updated.'));
    }
}
