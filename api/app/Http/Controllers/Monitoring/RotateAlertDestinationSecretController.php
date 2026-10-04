<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RotateAlertDestinationSecret;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RotateAlertDestinationSecretController
{
    /**
     * Issue a new signing secret and show it once.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertDestination  $destination
     * @param  RotateAlertDestinationSecret  $rotate
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertDestination $destination, RotateAlertDestinationSecret $rotate): JsonResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $rotate->handle($project->account, $user, $destination, $version);

        // The new signing secret is shown once.
        return response()->json(['redirect' => route('monitoring.destinations.show', [$project, $target->id], false), 'secrets' => ['signing_secret' => $target->signing_secret]]);
    }
}
