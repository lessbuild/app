<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateEnvironmentDeployNotifications;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentDeployNotificationsController
{
    /**
     * Save which alert destinations hear about the environment's deploys and return to its Notifications tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  UpdateEnvironmentDeployNotifications  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, UpdateEnvironmentDeployNotifications $update): JsonResponse
    {
        $request->validate(['destinations' => ['nullable', 'array'], 'destinations.*' => ['array'], 'destinations.*.*' => ['string']]);
        $update->handle($user, $environment, (array) $request->input('destinations', []));

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'notifications'], false), 'message' => __('Deploy notifications saved.')]);
    }
}
