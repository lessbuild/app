<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateEnvironmentRecipeSettings;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
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
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, UpdateEnvironmentRecipeSettings $update): RedirectResponse
    {
        $update->handle($user, $environment, $request->boolean('run_on_new_websites'));

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'recipes'])->with('status', __('Saved.'));
    }
}
