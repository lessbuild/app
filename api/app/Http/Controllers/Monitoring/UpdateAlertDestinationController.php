<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveAlertDestination;
use App\Http\Requests\Monitoring\AlertDestinationRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateAlertDestinationController
{
    /**
     * Save a destination.
     *
     * @param  AlertDestinationRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveAlertDestination  $save
     * @return JsonResponse
     */
    public function __invoke(AlertDestinationRequest $request, #[CurrentUser] User $user, Project $project, SaveAlertDestination $save): JsonResponse
    {
        $destination = $save->handle($project->account, $user, $request->validated(), $request->destination());

        return response()->json(['redirect' => route('monitoring.destinations.show', [$project, $destination->id], false), 'message' => __('Destination saved.')]);
    }
}
