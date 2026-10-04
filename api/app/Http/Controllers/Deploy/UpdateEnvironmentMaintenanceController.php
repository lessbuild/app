<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SetMaintenanceMode;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentMaintenanceController
{
    /**
     * Put an environment's websites into maintenance mode or bring them back, then return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SetMaintenanceMode  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SetMaintenanceMode $set): JsonResponse
    {
        $request->validate(['down' => ['required', 'boolean']]);
        $down = $request->boolean('down');
        $set->handle($user, $environment, $down);

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'controls'], false), 'message' => $down ? __('Going into maintenance mode. Visitors see a “back soon” page.') : __('Bringing the websites back up.')]);
    }
}
