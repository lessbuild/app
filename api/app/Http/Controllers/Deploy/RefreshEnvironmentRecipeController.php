<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RefreshEnvironmentRecipe;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RefreshEnvironmentRecipeController
{
    /**
     * Update an environment recipe from the library and return to the Recipes tab.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  string  $entry
     * @param  RefreshEnvironmentRecipe  $refresh
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, string $entry, RefreshEnvironmentRecipe $refresh): RedirectResponse
    {
        $refresh->handle($user, $environment->recipes()->findOrFail((int) $entry));

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'recipes'])->with('status', __('Recipe updated from the library.'));
    }
}
