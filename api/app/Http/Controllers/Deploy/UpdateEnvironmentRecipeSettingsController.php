<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateEnvironmentRecipeSettings;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentRecipeSettingsController
{
    /**
     * Save whether recipes run on new websites' servers and return to the Recipes tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  UpdateEnvironmentRecipeSettings  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, UpdateEnvironmentRecipeSettings $update): JsonResponse
    {
        $update->handle($user, $environment, $request->boolean('run_on_new_websites'));

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'recipes'], false), 'message' => __('Saved.')]);
    }
}
