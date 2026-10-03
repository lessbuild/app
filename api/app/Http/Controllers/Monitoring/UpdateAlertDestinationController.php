<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveAlertDestination;
use App\Http\Requests\Monitoring\AlertDestinationRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateAlertDestinationController
{
    /**
     * Save a destination.
     *
     * @param  AlertDestinationRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveAlertDestination  $save
     * @return RedirectResponse
     */
    public function __invoke(AlertDestinationRequest $request, #[CurrentUser] User $user, Project $project, SaveAlertDestination $save): RedirectResponse
    {
        $destination = $save->handle($project->account, $user, $request->validated(), $request->destination());

        return to_route('monitoring.destinations.show', [$project, $destination->id])->with('status', __('Destination saved.'));
    }
}
