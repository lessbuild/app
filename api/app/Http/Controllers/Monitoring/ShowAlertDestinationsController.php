<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\AlertDestinationOptions;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\TwilioAlerts;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowAlertDestinationsController
{
    /**
     * List where the account's alerts go (any project's monitors can use them), with the form's choices for adding one.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertDestinationsQuery  $destinations
     * @param  TwilioAlerts  $twilio
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, AlertDestinationsQuery $destinations, TwilioAlerts $twilio): JsonResponse
    {
        $canManage = $user->can('create', [AlertDestination::class, $project]);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'destinations' => array_map(fn (AlertDestination $destination): array => [
                'id' => $destination->id,
                'name' => $destination->name,
                'type' => $destination->type->label(),
                'target' => $destination->targetLabel(),
                'monitors' => (int) ($destination->monitors_count ?? 0),
                'enabled' => (bool) $destination->enabled,
            ], $destinations->handle($project->account_id)),
            'options' => $canManage ? AlertDestinationOptions::for($destinations, $twilio, $project->account_id) : null,
            'canManage' => $canManage,
        ]);
    }
}
