<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveAlertDestination;
use App\Enums\AlertDestinationType;
use App\Http\Requests\Monitoring\AlertDestinationRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreAlertDestinationController
{
    /**
     * Add a destination, showing a webhook's signing secret once.
     *
     * @param  AlertDestinationRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveAlertDestination  $save
     * @return RedirectResponse
     */
    public function __invoke(AlertDestinationRequest $request, #[CurrentUser] User $user, Project $project, SaveAlertDestination $save): RedirectResponse
    {
        $destination = $save->handle($project->account, $user, $request->validated());

        return to_route('monitoring.destinations.show', [$project, $destination->id])
            ->with('status', __('Destination added.'))
            ->with('issued_key', $destination->type === AlertDestinationType::Webhook ? $destination->signing_secret : null);
    }
}
