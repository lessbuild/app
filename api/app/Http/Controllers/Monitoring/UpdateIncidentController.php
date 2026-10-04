<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UpdateIncident;
use App\Http\Requests\Monitoring\IncidentRequest;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateIncidentController
{
    /**
     * Acknowledge, assign, annotate or resolve an incident.
     *
     * @param  IncidentRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Incident  $incident
     * @param  UpdateIncident  $update
     * @return JsonResponse
     */
    public function __invoke(IncidentRequest $request, #[CurrentUser] User $user, Project $project, Incident $incident, UpdateIncident $update): JsonResponse
    {
        $update->handle($incident, $user, $request->details());

        return response()->json(['redirect' => route('monitoring.incidents.show', [$project, $incident->id], false), 'message' => __('Incident updated.')]);
    }
}
