<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateIncident;
use App\Http\Requests\Monitoring\IncidentRequest;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateIncidentController
{
    /**
     * Acknowledges, assigns, annotates or resolves an incident.
     *
     * @param  IncidentRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Incident  $incident
     * @param  UpdateIncident  $update
     * @return RedirectResponse
     */
    public function __invoke(IncidentRequest $request, #[CurrentUser] User $user, Project $project, Incident $incident, UpdateIncident $update): RedirectResponse
    {
        $update->handle($incident, $user, $request->details());

        return to_route('monitoring.incidents.show', [$project, $incident->id])->with('status', __('Incident updated.'));
    }
}
