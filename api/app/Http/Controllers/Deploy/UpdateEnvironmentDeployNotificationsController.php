<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateEnvironmentDeployNotifications;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
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
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, UpdateEnvironmentDeployNotifications $update): RedirectResponse
    {
        $request->validate(['destinations' => ['nullable', 'array'], 'destinations.*' => ['array'], 'destinations.*.*' => ['string']]);
        $update->handle($user, $environment, (array) $request->input('destinations', []));

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'notifications'])->with('status', __('Deploy notifications saved.'));
    }
}
